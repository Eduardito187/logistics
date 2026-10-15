<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;

/**
 * Factory de zonas de cobertura (coverage_zones) para tests y seeders.
 *
 * La columna espacial `area` (geometry(Polygon,4326)) no es un atributo Eloquent: se rellena con un
 * cuadrado válido alrededor de un punto aleatorio mediante un UPDATE con PostGIS en afterCreating,
 * para que cada registro quede legible por ZoneMapper (que exige geometría). El id es un UUID v7
 * tipado. Como la geometría se escribe por SQL, esta factory requiere PostGIS (stack Feature).
 *
 * @extends Factory<ZoneRecord>
 */
final class ZoneRecordFactory extends Factory
{
    /** @var class-string<ZoneRecord> */
    protected $model = ZoneRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => ZoneId::generate()->toString(),
            'name' => 'Zone '.$this->faker->unique()->numberBetween(1, 99999),
            'parent_area_id' => null,
            'rules' => [
                'nodeId' => null,
                'services' => ['STD'],
                'baseMinutes' => $this->faker->numberBetween(30, 240),
                'productLimits' => [],
                'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
                'serviceDays' => [1, 2, 3, 4, 5],
            ],
            'active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ZoneRecord $record): void {
            // Cuadrado de ~0.04° de lado alrededor de un punto aleatorio sobre Bolivia (orden GeoJSON
            // [lng, lat]). Se escribe por SQL porque la geometría PostGIS no se mapea como atributo.
            $lng = $this->faker->randomFloat(4, -68.5, -58.0);
            $lat = $this->faker->randomFloat(4, -22.0, -10.0);
            $geojson = json_encode([
                'type' => 'Polygon',
                'coordinates' => [[
                    [$lng, $lat],
                    [$lng + 0.04, $lat],
                    [$lng + 0.04, $lat + 0.04],
                    [$lng, $lat + 0.04],
                    [$lng, $lat],
                ]],
            ]);

            DB::connection($record->getConnectionName())->statement(
                'UPDATE '.ZoneRecord::TABLE.' SET area = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) WHERE id = ?',
                [(string) $geojson, (string) $record->getKey()],
            );
        });
    }
}
