<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;

/**
 * Ids de los bultos (shipment_units) de cada envío por el query builder, EN LOTE (whereIn) y en orden
 * estable (unidad, pieza, id). Única fuente de esta lectura: la usan el enriquecedor de paradas y las vistas
 * de la entrega (units_total / unit_ids).
 */
final readonly class DbShipmentUnitIdsReader implements ShipmentUnitIdsReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipmentIds(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => trim($id) !== '')));
        if ($ids === []) {
            return [];
        }

        $byShipment = [];
        foreach (
            $this->connection->table('shipment_units')
                ->whereIn('shipment_id', $ids)
                ->orderBy('unit_index')
                ->orderBy('piece_index')
                ->orderBy('id')
                ->get(['id', 'shipment_id']) as $unit
        ) {
            $byShipment[(string) $unit->shipment_id][] = (string) $unit->id;
        }

        return $byShipment;
    }
}
