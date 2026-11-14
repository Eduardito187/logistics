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
     * Búsqueda por texto: código de pedido (`external_id` / `order_reference`), nombre o documento del
     * cliente. Sin distinguir mayúsculas; los comodines del usuario se escapan (busca el texto literal).
     */
    public static function search(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';

        // SQL literal por columna (nada dinámico en el texto de la consulta; el valor va por binding).
        $query->where(static function (Builder $where) use ($like): void {
            $where->whereRaw('lower(external_id) like ?', [$like])
                ->orWhereRaw('lower(order_reference) like ?', [$like])
                ->orWhereRaw('lower(customer_name) like ?', [$like])
                ->orWhereRaw('lower(customer_document_id) like ?', [$like]);
        });
    }

    /**
     * Listos para retirar: pedidos cuya recepción en su tienda DESTINO ya se concilió (la mercadería está en
     * la tienda) y que todavía no se entregaron ni se anularon. Lee ejecución en solo lectura con un EXISTS
     * correlacionado por `shipment_id`.
     */
    public static function readyForPickup(Builder $query, string $prefix = 'shipments.'): void
    {
        $query->whereNotIn($prefix.'status', [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value])
            ->whereExists(static function (Builder $received) use ($prefix): void {
                $received->selectRaw('1')
                    ->from('execution_node_receptions as rp_r')
                    ->whereColumn('rp_r.shipment_id', $prefix.'id')
                    ->whereColumn('rp_r.destination_node_id', $prefix.'destination_node_id')
                    ->where('rp_r.status', 'RECONCILED');
            });
    }

    /**
     * Pendientes de preparar: envíos ASIGNADOS (PLANNED o IN_EXECUTION) que todavía NO tienen entrega en
     * ejecución abierta. En el flujo vigente (decisión del dueño) ASIGNAR EL VEHÍCULO ES PREPARAR: eso abre
     * la entrega (`execution_deliveries`), así que la existencia de la entrega marca el envío como preparado
     * y lo saca de esta lista. Cubre los que solo tienen etiquetas emitidas (las etiquetas NO abren entrega,
     * no cuentan como preparación) y los asignados en planificación sin vehículo de reparto aún.
     *
     * OJO: antes esto filtraba por "sin escaneo de PREPARACIÓN", pero ese escaneo se eliminó del flujo (ya
     * no lo hace el app); si se vuelve a condicionar por el scan, ningún envío sale nunca de "por preparar".
     *
     * Lee ejecución en solo lectura (sin acoplar su dominio) con un NOT EXISTS correlacionado sobre
     * `execution_deliveries.shipment_id` (único): costo acotado por fila candidata.
     */
    public static function pendingPreparation(Builder $query, string $idColumn = 'shipments.id', string $statusColumn = 'shipments.status'): void
    {
        $query->whereIn($statusColumn, [ShipmentStatus::Planned->value, ShipmentStatus::InExecution->value])
            ->whereNotExists(static function (Builder $prepared) use ($idColumn): void {
                $prepared->selectRaw('1')
                    ->from('execution_deliveries as pp_d')
                    ->whereColumn('pp_d.shipment_id', $idColumn);
            });
    }
}
