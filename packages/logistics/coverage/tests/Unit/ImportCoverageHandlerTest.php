<?php

declare(strict_types=1);

use DateTimeImmutable;
use Logistics\Coverage\Application\Command\ImportCoverage;
use Logistics\Coverage\Application\Command\ImportCoverageHandler;
use Logistics\Coverage\Application\Report\ImportCoverageReport;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\Service\GeoJsonCoverageParser;
use Logistics\Coverage\Domain\Service\KmlCoverageParser;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\CoverageFormat;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Repositorio de zonas en memoria: upsert por id (clave primaria) y búsqueda por clave estable.
 */
function inMemoryZoneRepository(): ZoneRepository
{
    return new class implements ZoneRepository
    {
        /** @var array<string, Zone> */
        public array $zones = [];

        public function nextIdentity(): ZoneId
        {
            return ZoneId::generate();
        }

        public function save(Zone $zone): void
        {
            // Upsert por id: reimportar con el mismo id reemplaza, no agrega (como el ON CONFLICT real).
            $this->zones[$zone->id()->toString()] = $zone;
        }

        public function findById(ZoneId $id): ?Zone
        {
            return $this->zones[$id->toString()] ?? null;
        }

        public function findByNameAndParent(string $name, ?GeoAreaId $parentAreaId): ?Zone
        {
            foreach ($this->zones as $zone) {
                $sameParent = $parentAreaId === null
                    ? $zone->parentAreaId() === null
                    : $zone->parentAreaId()?->toString() === $parentAreaId->toString();
                if ($zone->name() === $name && $sameParent) {
                    return $zone;
                }
            }

            return null;
        }

        public function all(): array
        {
            return array_values($this->zones);
        }

        public function findZoneContaining(GeoPoint $point): ?Zone
        {
            return null;
        }
    };
}

/**
 * Repositorio de áreas administrativas en memoria.
 */
function inMemoryGeoAreaRepository(): GeoAreaRepository
{
    return new class implements GeoAreaRepository
    {
        /** @var array<string, GeoArea> */
        public array $areas = [];

        public function nextIdentity(): GeoAreaId
        {
            return GeoAreaId::generate();
        }

        public function save(GeoArea $area): void
        {
            $this->areas[$area->id()->toString()] = $area;
        }

        public function findById(GeoAreaId $id): ?GeoArea
        {
            return $this->areas[$id->toString()] ?? null;
        }

        public function findByNameAndLevel(string $name, AdminLevel $level): ?GeoArea
        {
            foreach ($this->areas as $area) {
                if ($area->name() === $name && $area->level() === $level) {
                    return $area;
                }
            }

            return null;
        }

        public function all(): array
        {
            return array_values($this->areas);
        }
    };
}

/**
 * RecordDomainEvents real, sobre un outbox en memoria y un reloj fijo.
 */
function recordDomainEventsInMemory(): RecordDomainEvents
{
    $store = new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $messages = [];

        public function append(OutboxMessage $message): void
        {
            $this->messages[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T12:00:00+00:00');
        }
    };

    return new RecordDomainEvents($store, $clock);
}

/**
 * Arma el handler con dependencias en memoria y devuelve el handler y sus colaboradores observables.
 *
 * @return array{0: ImportCoverageHandler, 1: ZoneRepository, 2: GeoAreaRepository, 3: ImportCoverageReport}
 */
function makeImportCoverageHandler(): array
{
    $zones = inMemoryZoneRepository();
    $areas = inMemoryGeoAreaRepository();
    $report = new ImportCoverageReport;

    $handler = new ImportCoverageHandler(
        $zones,
        $areas,
        new GeoJsonCoverageParser,
        new KmlCoverageParser,
        recordDomainEventsInMemory(),
        $report,
    );

    return [$handler, $zones, $areas, $report];
}

/**
 * GeoJSON de cobertura con las zonas indicadas (nombre → anillo cerrado por defecto).
 *
 * @param list<string> $names
 */
function coverageGeoJson(array $names): string
{
    $features = [];
    foreach ($names as $i => $name) {
        $base = -63.2 - ($i * 0.1);
        $features[] = [
            'type' => 'Feature',
            'properties' => ['name' => $name, 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$base, -17.8],
                    [$base + 0.05, -17.8],
                    [$base + 0.05, -17.7],
                    [$base, -17.7],
                    [$base, -17.8],
                ]],
            ],
        ];
    }

    return (string) json_encode(['type' => 'FeatureCollection', 'features' => $features]);
}

it('creates a department and its zones on first import', function (): void {
    [$handler, $zones, $areas, $report] = makeImportCoverageHandler();

    $handler->handle(new ImportCoverage('Santa Cruz', coverageGeoJson(['Centro', 'Norte']), CoverageFormat::GeoJson));

    expect($report->createdCount())->toBe(2)
        ->and($report->updatedCount())->toBe(0)
        ->and($report->errorCount())->toBe(0)
        ->and($zones->all())->toHaveCount(2)
        ->and($areas->all())->toHaveCount(1);

    $department = $areas->all()[0];
    expect($department->name())->toBe('Santa Cruz')
        ->and($department->level())->toBe(AdminLevel::Department);

    // Cada zona cuelga del departamento.
    foreach ($zones->all() as $zone) {
        expect($zone->parentAreaId()?->toString())->toBe($department->id()->toString());
    }
});

it('is idempotent: re-importing the same map updates, never duplicates', function (): void {
    [$handler, $zones, $areas, $report] = makeImportCoverageHandler();
    $geojson = coverageGeoJson(['Centro', 'Norte']);

    $handler->handle(new ImportCoverage('Santa Cruz', $geojson, CoverageFormat::GeoJson));
    $idsAfterFirst = array_map(fn (Zone $z): string => $z->id()->toString(), $zones->all());
    sort($idsAfterFirst);

    // Segunda importación idéntica.
    $handler->handle(new ImportCoverage('Santa Cruz', $geojson, CoverageFormat::GeoJson));

    expect($report->createdCount())->toBe(0)
        ->and($report->updatedCount())->toBe(2)
        ->and($zones->all())->toHaveCount(2)
        ->and($areas->all())->toHaveCount(1);

    // Los ids se conservan (upsert por clave estable, no alta nueva): deep-links intactos.
    $idsAfterSecond = array_map(fn (Zone $z): string => $z->id()->toString(), $zones->all());
    sort($idsAfterSecond);
    expect($idsAfterSecond)->toBe($idsAfterFirst);
});

it('reuses the department across imports (no duplicate department)', function (): void {
    [$handler, $zones, $areas] = makeImportCoverageHandler();

    $handler->handle(new ImportCoverage('Santa Cruz', coverageGeoJson(['Centro']), CoverageFormat::GeoJson));
    $handler->handle(new ImportCoverage('Santa Cruz', coverageGeoJson(['Norte']), CoverageFormat::GeoJson));

    expect($areas->all())->toHaveCount(1)
        ->and($zones->all())->toHaveCount(2);
});

it('reports per-feature errors and still imports the good features', function (): void {
    [$handler, $zones, , $report] = makeImportCoverageHandler();

    // Un feature válido y uno sin name: el malo se reporta, el bueno se importa.
    $decoded = json_decode(coverageGeoJson(['Centro']), true);
    $decoded['features'][] = [
        'type' => 'Feature',
        'properties' => [],
        'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-1, -1], [-1, 0], [0, 0], [-1, -1]]]],
    ];
    $content = (string) json_encode($decoded);

    $handler->handle(new ImportCoverage('Santa Cruz', $content, CoverageFormat::GeoJson));

    expect($report->createdCount())->toBe(1)
        ->and($report->errorCount())->toBe(1)
        ->and($zones->all())->toHaveCount(1)
        ->and($report->errors()[0]['reason'])->toContain('properties.name');
});

it('reports a single document error when the JSON is invalid', function (): void {
    [$handler, $zones, , $report] = makeImportCoverageHandler();

    $handler->handle(new ImportCoverage('Santa Cruz', 'not json at all', CoverageFormat::GeoJson));

    expect($report->createdCount())->toBe(0)
        ->and($report->errorCount())->toBe(1)
        ->and($zones->all())->toHaveCount(0)
        ->and($report->errors()[0]['item'])->toBe('document');
});

it('imports from KML as well', function (): void {
    [$handler, $zones, $areas, $report] = makeImportCoverageHandler();

    $kml = <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Document>
        <Placemark>
          <name>Centro</name>
          <Polygon><outerBoundaryIs><LinearRing><coordinates>
            -63.2,-17.8 -63.15,-17.8 -63.15,-17.7 -63.2,-17.7 -63.2,-17.8
          </coordinates></LinearRing></outerBoundaryIs></Polygon>
        </Placemark>
      </Document>
    </kml>
    KML;

    $handler->handle(new ImportCoverage('Santa Cruz', $kml, CoverageFormat::Kml));

    expect($report->createdCount())->toBe(1)
        ->and($report->errorCount())->toBe(0)
        ->and($zones->all())->toHaveCount(1)
        ->and($areas->all())->toHaveCount(1);

    // Reimportar el mismo KML actualiza, no duplica.
    $handler->handle(new ImportCoverage('Santa Cruz', $kml, CoverageFormat::Kml));
    expect($report->updatedCount())->toBe(1)
        ->and($zones->all())->toHaveCount(1);
});
