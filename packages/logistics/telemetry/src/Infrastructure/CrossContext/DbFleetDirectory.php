<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\CrossContext;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Telemetry\Application\Contract\FleetDirectory;

/**
 * Directorio de flota por query builder (patrón api-readmodel-enrichment): lee `fleet_vehicles`,
 * `fleet_drivers` y `execution_driver_routes` en solo lectura, sin modelos ni dependencia composer de esos
 * contextos. Una query por concepto (whereIn), sin N+1; degradación con gracia (null) si algo falta.
 */
final readonly class DbFleetDirectory implements FleetDirectory
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function plates(array $vehicleIds): array
    {
        return $this->pluck('fleet_vehicles', 'plate', $vehicleIds);
    }

    public function driverNames(array $driverIds): array
    {
        return $this->pluck('fleet_drivers', 'name', $driverIds);
    }

    public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array
    {
        $result = array_fill_keys($vehicleIds, null);
        $ids = SafeRead::uuids($vehicleIds);
        if ($ids === []) {
            return $result;
        }

        /** @var array<string, string|null> $found */
        $found = SafeRead::run($this->connection, function () use ($ids, $day): array {
            $map = [];
            $rows = $this->connection->table('execution_driver_routes')
                ->whereIn('vehicle_id', $ids)
                ->where('route_date', $day->format('Y-m-d'))
                ->whereNotNull('driver_id')
                ->get(['vehicle_id', 'driver_id']);
            foreach ($rows as $row) {
                $map[(string) $row->vehicle_id] = (string) $row->driver_id;
            }

            return $map;
        }, []);

        return array_replace($result, array_intersect_key($found, $result));
    }

    /**
     * Mapa id→columna de una tabla de otro contexto, con null para lo no encontrado.
     *
     * @param  list<string>               $ids
     * @return array<string, string|null>
     */
    private function pluck(string $table, string $column, array $ids): array
    {
        $result = array_fill_keys($ids, null);
        $valid = SafeRead::uuids($ids);
        if ($valid === []) {
            return $result;
        }

        /** @var array<string, string|null> $found */
        $found = SafeRead::run($this->connection, function () use ($table, $column, $valid): array {
            $map = [];
            foreach ($this->connection->table($table)->whereIn('id', $valid)->get(['id', $column]) as $row) {
                $value = $row->{$column} ?? null;
                $map[(string) $row->id] = is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
            }

            return $map;
        }, []);

        return array_replace($result, array_intersect_key($found, $result));
    }
}
