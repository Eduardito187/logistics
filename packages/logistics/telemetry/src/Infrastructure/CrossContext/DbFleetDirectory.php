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
 *
 * @phpstan-type HomeBase array{node_id: string, node_name: string|null, department: string|null}
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

    public function homeBases(array $vehicleIds): array
    {
        $ids = SafeRead::uuids($vehicleIds);
        if ($ids === []) {
            return [];
        }

        return SafeRead::run($this->connection, fn (): array => $this->readHomeBases($ids), []);
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

    public function vehicleIdsInDepartment(string $department): array
    {
        $department = strtolower(trim($department));
        if ($department === '') {
            return [];
        }

        return SafeRead::run($this->connection, fn (): array => $this->readVehicleIdsInDepartment($department), []);
    }

    public function retiredVehicleIds(?array $among = null): array
    {
        $statuses = RetiredVehicleStatuses::all();
        if ($statuses === []) {
            return [];
        }

        $only = $among === null ? null : SafeRead::uuids($among);
        if ($only === []) {
            return [];
        }

        return SafeRead::run($this->connection, fn (): array => $this->readRetiredVehicleIds($statuses, $only), []);
    }

    /**
     * Sede (nodo base) de cada vehículo, por id. Lanza si la lectura falla: quien llama la protege.
     *
     * @param  list<string>            $ids
     * @return array<string, HomeBase>
     */
    private function readHomeBases(array $ids): array
    {
        $map = [];
        $rows = $this->connection->table('fleet_vehicles as v')
            ->join('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
            ->whereIn('v.id', $ids)
            ->get(['v.id', 'n.id as node_id', 'n.name as node_name', 'n.department']);

        foreach ($rows as $row) {
            $map[(string) $row->id] = [
                'node_id' => (string) $row->node_id,
                'node_name' => is_string($row->node_name) && $row->node_name !== '' ? $row->node_name : null,
                'department' => is_string($row->department) && $row->department !== '' ? $row->department : null,
            ];
        }

        return $map;
    }

    /**
     * Ids de los vehículos con sede en ese departamento. Lanza si la lectura falla: quien llama la protege.
     *
     * @return list<string>
     */
    private function readVehicleIdsInDepartment(string $department): array
    {
        $ids = [];
        $rows = $this->connection->table('fleet_vehicles as v')
            ->join('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
            ->where('n.department', $department)
            ->get(['v.id']);

        foreach ($rows as $row) {
            $ids[] = (string) $row->id;
        }

        return $ids;
    }

    /**
     * Ids de los vehículos dados de baja (opcionalmente solo entre `$only`). Lanza si la lectura falla:
     * quien llama la protege.
     *
     * @param  list<string>      $statuses
     * @param  list<string>|null $only
     * @return list<string>
     */
    private function readRetiredVehicleIds(array $statuses, ?array $only): array
    {
        $query = $this->connection->table('fleet_vehicles')->whereIn('status', $statuses);
        if ($only !== null) {
            $query->whereIn('id', $only);
        }

        $ids = [];
        foreach ($query->get(['id']) as $row) {
            $ids[] = (string) $row->id;
        }

        return $ids;
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
