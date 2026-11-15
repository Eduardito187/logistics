<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Planning\Application\Contract\TripVehicleReader;
use Logistics\Planning\Application\ReadModel\TripVehicleView;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;
use Throwable;

/**
 * Adaptador read-side del camión y su base: una consulta `fleet_vehicles` ⨝ `network_nodes` por lote de
 * vehículos (solo lectura, sin modelos ni dependencia del dominio de Flota o de Red).
 *
 * El departamento de la base se normaliza con {@see DepartmentCode}, el mismo vocabulario del departamento
 * de destino de los envíos, para poder compararlos sin geocodificar nada.
 *
 * Degradación con gracia: si la lectura falla devuelve vacío; dentro de una transacción corre en un
 * savepoint para no abortar la transacción externa.
 */
final readonly class DbTripVehicleReader implements TripVehicleReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function vehiclesFor(array $vehicleIds): array
    {
        $ids = [];
        foreach ($vehicleIds as $id) {
            // Nunca se compara contra una columna uuid un valor que no tenga forma de UUID (22P02).
            if (preg_match(self::UUID_PATTERN, $id) === 1) {
                $ids[strtolower($id)] = true;
            }
        }
        $ids = array_map(strval(...), array_keys($ids));
        if ($ids === []) {
            return [];
        }

        try {
            if ($this->connection->transactionLevel() > 0) {
                /** @var array<string, TripVehicleView> $result */
                $result = $this->connection->transaction(fn (): array => $this->read($ids));

                return $result;
            }

            return $this->read($ids);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<string>                   $ids
     * @return array<string, TripVehicleView>
     */
    private function read(array $ids): array
    {
        $rows = $this->connection->table('fleet_vehicles as v')
            ->leftJoin('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
            ->whereIn('v.id', $ids)
            ->get([
                'v.id', 'v.plate', 'v.capacity_kg', 'v.capacity_m3',
                'n.id as node_id', 'n.name as node_name', 'n.lat as node_lat', 'n.lng as node_lng',
                'n.department as node_department',
            ]);

        $vehicles = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = self::text($data['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $id = strtolower($id);

            $hasPoint = is_numeric($data['node_lat'] ?? null) && is_numeric($data['node_lng'] ?? null);

            $vehicles[$id] = new TripVehicleView(
                vehicleId: $id,
                plate: self::text($data['plate'] ?? null),
                capacityKg: is_numeric($data['capacity_kg'] ?? null) ? max(0.0, (float) $data['capacity_kg']) : 0.0,
                capacityM3: is_numeric($data['capacity_m3'] ?? null) ? max(0.0, (float) $data['capacity_m3']) : 0.0,
                baseNodeId: self::text($data['node_id'] ?? null),
                baseNodeName: self::text($data['node_name'] ?? null),
                baseLatitude: $hasPoint ? (float) $data['node_lat'] : null,
                baseLongitude: $hasPoint ? (float) $data['node_lng'] : null,
                department: DepartmentCode::of(self::text($data['node_department'] ?? null)),
            );
        }

        return $vehicles;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
