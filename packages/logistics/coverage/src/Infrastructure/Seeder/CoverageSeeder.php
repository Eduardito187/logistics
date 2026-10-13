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
 * Seeder propio del contexto Cobertura con datos de demo realistas (Bolivia).
 *
 * Crea una jerarquía Departamento > Ciudad y un puñado de zonas de cobertura con polígonos reales
 * alrededor de Santa Cruz, La Paz y Cochabamba. Persiste SIEMPRE a través de los repositorios de
 * dominio (no inserta filas a mano) para que la geometría pase por PostGIS y las reglas por las
 * invariantes de ZoneRules. Requiere PostGIS levantado.
 *
 * El integrador lo invoca desde database/seeders/DatabaseSeeder.php; este package no toca ese
 * archivo compartido. Es idempotente por nombre: no re-crea áreas/zonas que ya existan.
 */
final class CoverageSeeder extends Seeder
{
    public function run(): void
    {
        /** @var GeoAreaRepository $areas */
        $areas = app(GeoAreaRepository::class);
        /** @var ZoneRepository $zones */
        $zones = app(ZoneRepository::class);

        $existingAreas = [];
        foreach ($areas->all() as $area) {
            $existingAreas[$area->name()] = $area;
        }

        $existingZones = [];
        foreach ($zones->all() as $zone) {
            $existingZones[$zone->name()] = true;
        }

        foreach ($this->catalogue() as $departmentName => $cities) {
            $department = $existingAreas[$departmentName] ?? null;
            if (!$department instanceof GeoArea) {
                $department = GeoArea::create($areas->nextIdentity(), $departmentName, AdminLevel::Department, null);
                $areas->save($department);
                $existingAreas[$departmentName] = $department;
            }

            foreach ($cities as $cityName => $zoneDefs) {
                $city = $existingAreas[$cityName] ?? null;
                if (!$city instanceof GeoArea) {
                    $city = GeoArea::create($areas->nextIdentity(), $cityName, AdminLevel::City, $department->id());
                    $areas->save($city);
                    $existingAreas[$cityName] = $city;
                }

                foreach ($zoneDefs as $zoneDef) {
                    if (isset($existingZones[$zoneDef['name']])) {
                        continue;
                    }
                    $zone = Zone::define(
                        $zones->nextIdentity(),
                        $zoneDef['name'],
                        $city->id(),
                        Polygon::fromRing($this->square($zoneDef['lng'], $zoneDef['lat'])),
                        ZoneRules::define(
                            $zoneDef['nodeId'],
                            $zoneDef['services'],
                            $zoneDef['baseMinutes'],
                            $zoneDef['productLimits'],
                            [TimeWindow::of('08:00', '18:00')],
                            ServiceDays::of(1, 2, 3, 4, 5, 6),
                        ),
                    );
                    $zones->save($zone);
                    $existingZones[$zoneDef['name']] = true;
                }
            }
        }
    }

    /**
     * Catálogo de demo: departamento => ciudad => zonas con su punto central y reglas.
     *
     * @return array<string, array<string, array<int, array{
     *     name: string, lng: float, lat: float, nodeId: string|null,
     *     services: array<int, string>, baseMinutes: int, productLimits: array<string, int>
     * }>>>
     */
    private function catalogue(): array
    {
        return [
            'Santa Cruz' => [
                'Santa Cruz de la Sierra' => [
                    ['name' => 'Centro SCZ', 'lng' => -63.182, 'lat' => -17.783, 'nodeId' => null, 'services' => ['STD', 'EXPRESS'], 'baseMinutes' => 120, 'productLimits' => []],
                    ['name' => 'Equipetrol', 'lng' => -63.197, 'lat' => -17.762, 'nodeId' => null, 'services' => ['STD', 'EXPRESS'], 'baseMinutes' => 90, 'productLimits' => []],
                    ['name' => 'Plan Tres Mil', 'lng' => -63.120, 'lat' => -17.830, 'nodeId' => null, 'services' => ['STD'], 'baseMinutes' => 180, 'productLimits' => ['BULKY' => 2]],
                ],
            ],
            'La Paz' => [
                'La Paz' => [
                    ['name' => 'Centro La Paz', 'lng' => -68.133, 'lat' => -16.500, 'nodeId' => null, 'services' => ['STD'], 'baseMinutes' => 150, 'productLimits' => []],
                    ['name' => 'Sopocachi', 'lng' => -68.130, 'lat' => -16.515, 'nodeId' => null, 'services' => ['STD', 'EXPRESS'], 'baseMinutes' => 120, 'productLimits' => []],
                ],
                'El Alto' => [
                    ['name' => 'Ceja El Alto', 'lng' => -68.163, 'lat' => -16.503, 'nodeId' => null, 'services' => ['STD'], 'baseMinutes' => 180, 'productLimits' => ['BULKY' => 1]],
                ],
            ],
            'Cochabamba' => [
                'Cochabamba' => [
                    ['name' => 'Centro Cochabamba', 'lng' => -66.157, 'lat' => -17.389, 'nodeId' => null, 'services' => ['STD', 'EXPRESS'], 'baseMinutes' => 120, 'productLimits' => []],
                ],
            ],
        ];
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
