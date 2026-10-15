<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Application\Report\ImportCoverageReport;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\Service\GeoJsonCoverageParser;
use Logistics\Coverage\Domain\Service\KmlCoverageParser;
use Logistics\Coverage\Domain\Service\ParsedZone;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\CoverageFormat;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Importa, de forma idempotente, el mapa de cobertura de un departamento desde KML o GeoJSON.
 *
 * Idempotencia (no duplicar):
 *  - El departamento se reusa por su clave estable (nombre + nivel Department): se da de alta solo si
 *    no existe; en reimportaciones se reutiliza el mismo GeoArea, con su mismo UUID.
 *  - Cada zona se reusa por su clave estable (nombre + área padre): si ya existe se reconstruye con su
 *    MISMO ZoneId y se persiste encima (upsert ON CONFLICT (id) del repositorio PostGIS), de modo que
 *    la geometría y las reglas se actualizan sin cambiar el id ni romper los deep-links del admin.
 *
 * Cada zona importada emite su evento ZoneDefined (el snapshot autoritativo de la zona) al outbox,
 * dentro de la transacción que abre el decorador transaccional del bus; el relay es idempotente.
 *
 * El parseo lo hacen los servicios de dominio ya existentes (GeoJsonCoverageParser / KmlCoverageParser);
 * acá solo se orquesta. Los resultados (altas, actualizaciones y errores) se recogen en el
 * ImportCoverageReport para que la capa de Presentation los muestre, ya que el handler es void (CQRS).
 *
 * @implements CommandHandler<ImportCoverage>
 */
final readonly class ImportCoverageHandler implements CommandHandler
{
    public function __construct(
        private ZoneRepository $zones,
        private GeoAreaRepository $areas,
        private GeoJsonCoverageParser $geoJsonParser,
        private KmlCoverageParser $kmlParser,
        private RecordDomainEvents $recordEvents,
        private ImportCoverageReport $report,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof ImportCoverage);

        // Cada importación parte de un reporte limpio (el colector es de alcance de request).
        $this->report->reset();

        $department = $this->resolveDepartment($command->departmentName);

        foreach ($this->parseZones($command) as $parsed) {
            $this->upsertZone($parsed, $department->id());
        }
    }

    /**
     * Da de alta el departamento si no existe (clave estable: nombre + nivel Department) o reusa el
     * existente. GeoArea es una entidad simple sin eventos: se persiste directo por su repositorio.
     */
    private function resolveDepartment(string $name): GeoArea
    {
        $existing = $this->areas->findByNameAndLevel($name, AdminLevel::Department);
        if ($existing !== null) {
            return $existing;
        }

        $department = GeoArea::create(
            $this->areas->nextIdentity(),
            $name,
            AdminLevel::Department,
            null,
        );
        $this->areas->save($department);

        return $department;
    }

    /**
     * Parsea el documento según su formato, recolectando los errores de parseo en el reporte y
     * devolviendo solo las zonas que se pudieron interpretar.
     *
     * @return list<ParsedZone>
     */
    private function parseZones(ImportCoverage $command): array
    {
        return match ($command->format) {
            CoverageFormat::GeoJson => $this->parseGeoJson($command->content),
            CoverageFormat::Kml => $this->parseKml($command->content),
        };
    }

    /**
     * GeoJSON: se parsea feature por feature para que un feature malformado no tumbe toda la
     * importación; cada error se registra con el nombre del feature (o su índice) como referencia.
     *
     * @return list<ParsedZone>
     */
    private function parseGeoJson(string $content): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $this->report->recordError('document', 'el contenido no es un JSON válido');

            return [];
        }

        $features = $decoded['features'] ?? null;
        if (($decoded['type'] ?? null) !== 'FeatureCollection' || !is_array($features)) {
            $this->report->recordError('document', 'se esperaba un GeoJSON FeatureCollection con "features"');

            return [];
        }

        $zones = [];
        foreach ($features as $index => $feature) {
            $label = "feature #{$index}";
            if (!is_array($feature)) {
                $this->report->recordError($label, 'cada feature debe ser un objeto');

                continue;
            }

            $properties = $feature['properties'] ?? null;
            if (is_array($properties) && isset($properties['name']) && is_string($properties['name']) && $properties['name'] !== '') {
                $label = $properties['name'];
            }

            try {
                // Se reusa el parser de dominio sobre una colección de un solo feature.
                foreach ($this->geoJsonParser->parse(['type' => 'FeatureCollection', 'features' => [$feature]]) as $parsed) {
                    $zones[] = $parsed;
                }
            } catch (CoverageException $e) {
                $this->report->recordError($label, $e->getMessage());
            }
        }

        return $zones;
    }

    /**
     * KML: el parser de dominio procesa el documento completo (no es trivial trocear el XML por
     * Placemark sin reconstruir el DOM); un documento malformado se reporta como un único error.
     *
     * @return list<ParsedZone>
     */
    private function parseKml(string $content): array
    {
        try {
            return array_values($this->kmlParser->parse($content));
        } catch (CoverageException $e) {
            $this->report->recordError('document', $e->getMessage());

            return [];
        }
    }

    /**
     * Da de alta o actualiza una zona por su clave estable (nombre + área padre), colgándola del
     * departamento. Un error de dominio al construir/guardar una zona se registra y no detiene al resto.
     */
    private function upsertZone(ParsedZone $parsed, GeoAreaId $departmentId): void
    {
        try {
            $existing = $this->zones->findByNameAndParent($parsed->name, $departmentId);
            $id = $existing?->id() ?? $this->zones->nextIdentity();

            $zone = Zone::define($id, $parsed->name, $departmentId, $parsed->polygon, $parsed->rules);
            $this->zones->save($zone);
            $this->recordEvents->record($zone->releaseEvents(), 'coverage.zone');

            if ($existing !== null) {
                $this->report->recordUpdated($parsed->name);
            } else {
                $this->report->recordCreated($parsed->name);
            }
        } catch (CoverageException $e) {
            $this->report->recordError($parsed->name, $e->getMessage());
        }
    }
}
