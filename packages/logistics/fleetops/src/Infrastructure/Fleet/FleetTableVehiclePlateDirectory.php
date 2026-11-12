<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Fleet;

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Domain\Contract\VehiclePlateDirectory;
use Throwable;

/**
 * Placas desde `fleet_vehicles` en una sola consulta (lectura cruzada por valor dentro del monolito).
 *
 * Solo consulta ids con forma de UUID (la columna es uuid en Postgres). Es una lectura FUERA de
 * transacción de escritura (la usan las proyecciones de lectura), así que si la tabla no existe en este
 * entorno degrada a mapa vacío.
 */
final readonly class FleetTableVehiclePlateDirectory implements VehiclePlateDirectory
{
    public function platesFor(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $vehicleIds,
            static fn (string $id): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1,
        )));

        if ($ids === []) {
            return [];
        }

        try {
            $plates = [];
            foreach (DB::table('fleet_vehicles')->whereIn('id', $ids)->get(['id', 'plate']) as $row) {
                if (is_string($row->id ?? null) && is_string($row->plate ?? null)) {
                    $plates[$row->id] = $row->plate;
                }
            }

            return $plates;
        } catch (Throwable) {
            return [];
        }
    }
}
