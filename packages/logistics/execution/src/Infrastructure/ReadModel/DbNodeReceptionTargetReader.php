<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\NodeReceptionTargetReader;

/**
 * Deriva el destino, el origen y el manifiesto de una recepción en nodo leyendo read-only (query builder,
 * sin el Eloquent de otros contextos) shipments / shipment_units / execution_deliveries / fleet_vehicles /
 * network_legs / network_nodes. Cada método es una o dos consultas, nunca un N+1.
 *
 * Al extraer ejecución a un servicio aparte, esta implementación pasa a llamar a las APIs de esos
 * contextos; la puerta de Application no cambia.
 */
final readonly class DbNodeReceptionTargetReader implements NodeReceptionTargetReader
{
    /** Tipos de nodo de network que, para la recepción, son un centro de distribución. */
    private const array CD_TYPES = ['distribution_center', 'central_warehouse'];

    /** Tipos de nodo de network que, para la recepción, son una tienda. */
    private const array STORE_TYPES = ['store', 'pickup_point'];

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function shipment(string $shipmentId): ?array
    {
        if (!self::isUuid($shipmentId)) {
            return null;
        }

        $row = $this->connection->table('shipments')->where('id', $shipmentId)->first(['id', 'destination_node_id']);
        if ($row === null) {
            return null;
        }

        // Productos físicos en orden estable (línea, unidad, pieza): el manifiesto esperado.
        $unitIds = $this->connection->table('shipment_units')
            ->where('shipment_id', $shipmentId)
            ->orderBy('line_id')
            ->orderBy('unit_index')
            ->orderBy('piece_index')
            ->pluck('id')
            ->map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();

        // Nodo base del vehículo que transporta el envío (si la entrega ya tiene camión asignado).
        $home = $this->connection->table('execution_deliveries')
            ->join('fleet_vehicles', 'fleet_vehicles.id', '=', 'execution_deliveries.vehicle_id')
            ->where('execution_deliveries.shipment_id', $shipmentId)
            ->value('fleet_vehicles.home_base_node_id');

        return [
            'destination_node_id' => self::nullableString($row->destination_node_id ?? null),
            'unit_ids' => array_values($unitIds),
            'vehicle_home_node_id' => self::nullableString($home),
        ];
    }

    public function defaultOriginFor(string $destinationNodeId): ?string
    {
        if (!self::isUuid($destinationNodeId)) {
            return null;
        }

        // 1) El centro de distribución que abastece al destino por la red (tramo hacia el destino).
        $fromLeg = $this->connection->table('network_legs')
            ->join('network_nodes', 'network_nodes.id', '=', 'network_legs.from_node_id')
            ->where('network_legs.to_node_id', $destinationNodeId)
            ->where('network_legs.from_node_id', '!=', $destinationNodeId)
            ->where('network_nodes.status', 'active')
            ->whereIn('network_nodes.type', self::CD_TYPES)
            ->orderBy('network_nodes.code')
            ->value('network_nodes.id');
        if (self::nullableString($fromLeg) !== null) {
            return self::nullableString($fromLeg);
        }

        // 2) El centro de distribución activo principal (almacén central primero, luego por código).
        $main = $this->connection->table('network_nodes')
            ->where('id', '!=', $destinationNodeId)
            ->where('status', 'active')
            ->whereIn('type', self::CD_TYPES)
            ->orderByRaw("CASE WHEN type = 'central_warehouse' THEN 0 ELSE 1 END")
            ->orderBy('code')
            ->value('id');

        return self::nullableString($main);
    }

    public function nodeKinds(array $nodeIds): array
    {
        $ids = array_values(array_unique(array_filter($nodeIds, self::isUuid(...))));
        if ($ids === []) {
            return [];
        }

        $kinds = [];
        foreach ($this->connection->table('network_nodes')->whereIn('id', $ids)->get(['id', 'type']) as $row) {
            $type = is_string($row->type ?? null) ? $row->type : '';
            $kind = match (true) {
                in_array($type, self::CD_TYPES, true) => 'CD',
                in_array($type, self::STORE_TYPES, true) => 'STORE',
                default => null,
            };
            if ($kind !== null) {
                $kinds[(string) $row->id] = $kind;
            }
        }

        return $kinds;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(self::UUID_PATTERN, $value) === 1;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
