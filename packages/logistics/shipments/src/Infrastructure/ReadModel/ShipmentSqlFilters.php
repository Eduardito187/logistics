<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;

/**
 * Predicados SQL reutilizables del lado de lectura de envíos (listado, resumen y conteos por estado), para
 * que todos los lectores filtren EXACTAMENTE igual.
 */
final class ShipmentSqlFilters
{
    /**
     * Filtro por modalidad de entrega DERIVADA (ver DeliveryMode): recojo en tienda ⇔ el nodo destino es
     * una tienda o un punto de recojo; domicilio ⇔ sin nodo destino o nodo de otro tipo/desconocido.
     *
     * La subconsulta lee la tabla CHICA de nodos de red (filtrada por `type`, indexada) y el predicado
     * sobre `destination_node_id` se apoya en su índice: no hace falta una columna nueva en `shipments`.
     */
    public static function deliveryMode(Builder $query, string $mode, string $column = 'destination_node_id'): void
    {
        $pickupNodes = static function (Builder $sub): void {
            $sub->select('id')->from('network_nodes')->whereIn('type', DeliveryMode::pickupNodeTypes());
        };

        if ($mode === DeliveryMode::StorePickup->value) {
            $query->whereIn($column, $pickupNodes);

            return;
        }

        $query->where(static function (Builder $where) use ($column, $pickupNodes): void {
            $where->whereNull($column)->orWhereNotIn($column, $pickupNodes);
        });
    }
}
