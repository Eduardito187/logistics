<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Predicados SQL reutilizables del lado de lectura de envíos (listado, resumen y conteos por estado), para
 * que todos los lectores filtren EXACTAMENTE igual.
 */
final class ShipmentSqlFilters
{
    /**
     * Filtro por modalidad de entrega DERIVADA (ver DeliveryMode), equivalente a ShipmentListSupport:
     *  - transfer      ⇔ fuente interna (OrderSource::Transfer) Y con nodo destino;
     *  - store_pickup  ⇔ pedido de cliente (fuente NO interna) cuyo nodo destino es tienda / punto de recojo;
     *  - home_delivery ⇔ sin nodo destino, o pedido de cliente a un nodo de otro tipo / desconocido.
     *
     * La subconsulta lee la tabla CHICA de nodos de red (filtrada por `type`, indexada) y el predicado
     * sobre `destination_node_id` se apoya en su índice: no hace falta una columna nueva en `shipments`.
     */
    public static function deliveryMode(Builder $query, string $mode, string $column = 'destination_node_id', string $sourceColumn = 'source'): void
    {
        $pickupNodes = static function (Builder $sub): void {
            $sub->select('id')->from('network_nodes')->whereIn('type', DeliveryMode::pickupNodeTypes());
        };
        $internal = OrderSource::internalTransferValues();

        if ($mode === DeliveryMode::Transfer->value) {
            $query->whereNotNull($column)->whereIn($sourceColumn, $internal);

            return;
        }

        if ($mode === DeliveryMode::StorePickup->value) {
            $query->whereIn($column, $pickupNodes)->whereNotIn($sourceColumn, $internal);

            return;
        }

        $query->where(static function (Builder $where) use ($column, $sourceColumn, $pickupNodes, $internal): void {
            $where->whereNull($column)->orWhere(static function (Builder $customer) use ($column, $sourceColumn, $pickupNodes, $internal): void {
                $customer->whereNotIn($column, $pickupNodes)->whereNotIn($sourceColumn, $internal);
            });
        });
    }

    /**
     * Pendientes de preparar (auditoría app #1b): envíos ASIGNADOS (PLANNED o IN_EXECUTION) cuya entrega en
     * ejecución no tiene todavía ningún escaneo de PREPARACIÓN. Cubre los que no tienen entrega aún (camión
     * asignado en planificación, nada escaneado) y los que ya tienen etiquetas emitidas pero no se
     * prepararon: las etiquetas no cuentan como preparación, solo el escaneo.
     *
     * Lee las tablas de ejecución en solo lectura (sin acoplar su dominio) con un NOT EXISTS correlacionado
     * que se apoya en `execution_deliveries.shipment_id` (único) y en el índice
     * `execution_scan_events (delivery_id, type, occurred_at)`: costo acotado por fila candidata.
     */
    public static function pendingPreparation(Builder $query, string $idColumn = 'shipments.id', string $statusColumn = 'shipments.status'): void
    {
        $query->whereIn($statusColumn, [ShipmentStatus::Planned->value, ShipmentStatus::InExecution->value])
            ->whereNotExists(static function (Builder $prepared) use ($idColumn): void {
                $prepared->selectRaw('1')
                    ->from('execution_deliveries as pp_d')
                    ->join('execution_scan_events as pp_e', 'pp_e.delivery_id', '=', 'pp_d.id')
                    ->whereColumn('pp_d.shipment_id', $idColumn)
                    ->where('pp_e.type', 'PREPARATION');
            });
    }
}
