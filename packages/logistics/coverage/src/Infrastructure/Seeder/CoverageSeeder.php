<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Seeder;

use Illuminate\Database\Seeder;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ServiceDays;
use Logistics\Coverage\Domain\ValueObject\TimeWindow;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Seeder propio del contexto Cobertura con la jerarquía geográfica real de Bolivia (datos de demo).
 *
 * Crea los 9 departamentos y, para La Paz, la cadena completa Departamento > Provincia > Municipio >
 * Ciudad con sus zonas de cobertura (polígonos reales). Santa Cruz y Cochabamba se modelan con la
 * cadena corta Departamento > Ciudad para demostrar que la resolución tolera que falten niveles
 * intermedios. Persiste SIEMPRE a través de los repositorios de dominio (no inserta filas a mano) para
 * que la geometría pase por PostGIS y las reglas por las invariantes de ZoneRules. Requiere PostGIS.
 *
 * Es idempotente por (nivel + nombre): no re-crea áreas ni zonas que ya existan, así que puede correr
 * sobre una base ya poblada. El integrador lo invoca desde database/seeders/DatabaseSeeder.php; este
 * package no toca ese archivo compartido.
 */
final class CoverageSeeder extends Seeder
{
    /**
     * Áreas ya persistidas, indexadas por "nivel|nombre" para distinguir homónimos entre niveles
     * (p. ej. "La Paz" existe como departamento y como ciudad).
     *
     * @var array<string, GeoArea>
     */
    private array $areas = [];

    /**
     * Nombres de zonas ya persistidas, para no duplicarlas.
     *
     * @var array<string, true>
     */
    private array $zones = [];

    private GeoAreaRepository $areaRepository;

    private ZoneRepository $zoneRepository;

    public function run(): void
    {
        /** @var GeoAreaRepository $areaRepository */
        $areaRepository = app(GeoAreaRepository::class);
        $this->areaRepository = $areaRepository;
        /** @var ZoneRepository $zoneRepository */
        $zoneRepository = app(ZoneRepository::class);
        $this->zoneRepository = $zoneRepository;

        foreach ($this->areaRepository->all() as $area) {
            $this->areas[$this->key($area->level(), $area->name())] = $area;
        }
        foreach ($this->zoneRepository->all() as $zone) {
            $this->zones[$zone->name()] = true;
        }

        $this->seedLaPaz();
        $this->seedSantaCruz();
        $this->seedCochabamba();
        $this->seedRemainingDepartments();
    }

    /**
     * La Paz con la cadena completa: Departamento > Provincia > Municipio > Ciudad > Zona.
     */
    private function seedLaPaz(): void
    {
        $laPaz = $this->ensureArea('La Paz', AdminLevel::Department, null);

        // Provincia Pedro Domingo Murillo: contiene las ciudades de La Paz y El Alto.
        $murillo = $this->ensureArea('Pedro Domingo Murillo', AdminLevel::Province, $laPaz);

        $municipalityLaPaz = $this->ensureArea('Nuestra Señora de La Paz', AdminLevel::Municipality, $murillo);
        $cityLaPaz = $this->ensureArea('La Paz', AdminLevel::City, $municipalityLaPaz);
        $this->ensureZone('Centro La Paz', $cityLaPaz, -68.133, -16.500, null, ['STD'], 150, []);
        $this->ensureZone('Sopocachi', $cityLaPaz, -68.130, -16.515, null, ['STD', 'EXPRESS'], 120, []);

        $municipalityElAlto = $this->ensureArea('El Alto', AdminLevel::Municipality, $murillo);
        $cityElAlto = $this->ensureArea('El Alto', AdminLevel::City, $municipalityElAlto);
        $this->ensureZone('Ceja El Alto', $cityElAlto, -68.163, -16.503, null, ['STD'], 180, ['BULKY' => 1]);

        // Otros municipios de Murillo sin zonas de demo (completan la jerarquía).
        $this->ensureArea('Palca', AdminLevel::Municipality, $murillo);
        $this->ensureArea('Mecapaca', AdminLevel::Municipality, $murillo);
        $this->ensureArea('Achocalla', AdminLevel::Municipality, $murillo);

        // Más provincias de La Paz con algunos de sus municipios (ejemplo de jerarquía real).
        $losAndes = $this->ensureArea('Los Andes', AdminLevel::Province, $laPaz);
        $this->ensureArea('Pucarani', AdminLevel::Municipality, $losAndes);
        $this->ensureArea('Laja', AdminLevel::Municipality, $losAndes);
        $this->ensureArea('Batallas', AdminLevel::Municipality, $losAndes);

        $omasuyos = $this->ensureArea('Omasuyos', AdminLevel::Province, $laPaz);
        $this->ensureArea('Achacachi', AdminLevel::Municipality, $omasuyos);
        $this->ensureArea('Ancoraimes', AdminLevel::Municipality, $omasuyos);

        $ingavi = $this->ensureArea('Ingavi', AdminLevel::Province, $laPaz);
        $this->ensureArea('Viacha', AdminLevel::Municipality, $ingavi);
        $this->ensureArea('Guaqui', AdminLevel::Municipality, $ingavi);
    }

    /**
     * Santa Cruz con cadena corta Departamento > Ciudad (sin provincia ni municipio modelados).
     */
    private function seedSantaCruz(): void
    {
        $santaCruz = $this->ensureArea('Santa Cruz', AdminLevel::Department, null);
        $city = $this->ensureArea('Santa Cruz de la Sierra', AdminLevel::City, $santaCruz);

        $this->ensureZone('Centro SCZ', $city, -63.182, -17.783, null, ['STD', 'EXPRESS'], 120, []);
        $this->ensureZone('Equipetrol', $city, -63.197, -17.762, null, ['STD', 'EXPRESS'], 90, []);
        $this->ensureZone('Plan Tres Mil', $city, -63.120, -17.830, null, ['STD'], 180, ['BULKY' => 2]);
    }

    /**
     * Cochabamba con cadena corta Departamento > Ciudad.
     */
    private function seedCochabamba(): void
    {
        $cochabamba = $this->ensureArea('Cochabamba', AdminLevel::Department, null);
        $city = $this->ensureArea('Cochabamba', AdminLevel::City, $cochabamba);

        $this->ensureZone('Centro Cochabamba', $city, -66.157, -17.389, null, ['STD', 'EXPRESS'], 120, []);
    }

    /**
     * Los seis departamentos restantes de Bolivia, sin zonas de demo (completan los 9 departamentos).
     */
    private function seedRemainingDepartments(): void
    {
        foreach (['Oruro', 'Potosí', 'Chuquisaca', 'Tarija', 'Beni', 'Pando'] as $name) {
            $this->ensureArea($name, AdminLevel::Department, null);
        }
    }

    /**
     * Da de alta un área (idempotente por nivel + nombre) o reusa la existente.
     */
    private function ensureArea(string $name, AdminLevel $level, ?GeoArea $parent): GeoArea
    {
        $key = $this->key($level, $name);
        $existing = $this->areas[$key] ?? null;
        if ($existing instanceof GeoArea) {
            return $existing;
        }

        $area = GeoArea::create($this->areaRepository->nextIdentity(), $name, $level, $parent?->id());
        $this->areaRepository->save($area);
        $this->areas[$key] = $area;

        return $area;
    }

    /**
     * Da de alta una zona de cobertura colgada de un área (idempotente por nombre).
     *
     * @param array<int, string> $services
     * @param array<string, int> $productLimits
     */
    private function ensureZone(
        string $name,
        GeoArea $parentArea,
        float $lng,
        float $lat,
        ?string $nodeId,
        array $services,
        int $baseMinutes,
        array $productLimits,
    ): void {
        if (isset($this->zones[$name])) {
            return;
        }

        $zone = Zone::define(
            $this->zoneRepository->nextIdentity(),
            $name,
            $parentArea->id(),
            Polygon::fromRing($this->square($lng, $lat)),
            ZoneRules::define(
                $nodeId,
                $services,
                $baseMinutes,
                $productLimits,
                [TimeWindow::of('08:00', '18:00')],
                ServiceDays::of(1, 2, 3, 4, 5, 6),
            ),
        );
        $this->zoneRepository->save($zone);
        $this->zones[$name] = true;
    }

    private function key(AdminLevel $level, string $name): string
    {
        return $level->value.'|'.$name;
    }

    /**
     * Cuadrado cerrado de ~0.03° de lado centrado en (lng, lat), en orden GeoJSON [lng, lat].
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function square(float $lng, float $lat): array
    {
        $half = 0.015;

        return [
            [$lng - $half, $lat - $half],
            [$lng + $half, $lat - $half],
            [$lng + $half, $lat + $half],
            [$lng - $half, $lat + $half],
            [$lng - $half, $lat - $half],
        ];
    }
}
