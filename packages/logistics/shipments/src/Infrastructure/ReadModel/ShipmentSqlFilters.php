<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\ReadModel\AddressPartsView;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\DepartmentCode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Predicados SQL reutilizables del lado de lectura de envíos (listado, resumen y conteos por estado), para
 * que todos los lectores filtren EXACTAMENTE igual.
 */
final class ShipmentSqlFilters
{
    /** Estados de una ruta que sigue vigente (armada o en curso). */
    private const array ROUTE_OPEN = ['PLANNED', 'IN_PROGRESS'];

    /**
     * ALCANCE POR ROL: la regla única de qué envíos puede listar y operar un actor (ver ShipmentScopeActor).
     * La usan el listado, los conteos, `can_process` de la consulta de pedidos y la guarda de escrituras.
     *
     * Las habilitaciones del actor se combinan con OR (alcanza con que una cubra el envío):
     *  - ciudad (preparador): el departamento de ORIGEN o el de DESTINO es el suyo. Un envío que todavía no
     *    tiene NINGUNO de los dos resueltos se muestra igual: no se esconde un pedido por un dato faltante;
     *  - tienda: el envío SALE de su nodo o LLEGA a él;
     *  - chofer: el envío tiene una parada en una ruta suya, o una entrega en el vehículo de una ruta suya
     *    vigente. El chofer de una ruta es el grabado en ella o, si no lo tiene, el de la tripulación del
     *    vehículo ese día (misma regla que los KPIs y el historial del chofer);
     *  - transportista: el envío va en un viaje suyo.
     * Un actor restringido sin ninguna habilitación no ve nada. Sin restricción no agrega ningún predicado.
     *
     * Los predicados de ciudad y tienda son igualdades sobre columnas indexadas; los de asignación son
     * EXISTS correlacionados por `shipment_id` (indexado), sin una consulta por fila.
     */
    public static function scope(Builder $query, ShipmentScopeActor $actor, string $prefix = 'shipments.'): void
    {
        if ($actor->unrestricted) {
            return;
        }

        if ($actor->seesNothing()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(static function (Builder $scope) use ($actor, $prefix): void {
            if ($actor->department !== null) {
                $department = $actor->department;
                $scope->orWhere(static function (Builder $city) use ($department, $prefix): void {
                    $city->where($prefix.'origin_department', $department)
                        ->orWhere($prefix.'destination_department', $department)
                        ->orWhere(static function (Builder $unresolved) use ($prefix): void {
                            $unresolved->whereNull($prefix.'origin_department')->whereNull($prefix.'destination_department');
                        });
                });
            }

            if ($actor->storeNodeId !== null) {
                $nodeId = $actor->storeNodeId;
                $scope->orWhere(static function (Builder $store) use ($nodeId, $prefix): void {
                    $store->where($prefix.'destination_node_id', $nodeId)->orWhere($prefix.'origin_node_id', $nodeId);
                });
            }

            if ($actor->driverId !== null) {
                self::orAssignedToDriver($scope, $actor->driverId, $prefix);
            }

            if ($actor->carrierId !== null) {
                $carrierId = $actor->carrierId;
                $scope->orWhereExists(static function (Builder $trip) use ($carrierId, $prefix): void {
                    $trip->selectRaw('1')
                        ->from('carrier_trip_shipments as sc_ts')
                        ->join('carrier_trips as sc_t', 'sc_t.id', '=', 'sc_ts.trip_id')
                        ->whereColumn('sc_ts.shipment_id', $prefix.'id')
                        ->where('sc_t.carrier_id', $carrierId);
                });
            }
        });
    }

    /**
     * Envíos asignados a un chofer: parada en una ruta suya (cualquiera, también las ya cerradas: lo que
     * repartió sigue siendo suyo y un escaneo sin conexión puede llegar tarde), o entrega en el vehículo de
     * una ruta suya que sigue vigente.
     */
    private static function orAssignedToDriver(Builder $scope, string $driverId, string $prefix): void
    {
        $scope->orWhereExists(static function (Builder $stop) use ($driverId, $prefix): void {
            $stop->selectRaw('1')
                ->from('execution_driver_route_stops as sc_s')
                ->join('execution_driver_routes as sc_r', 'sc_r.id', '=', 'sc_s.route_id')
                ->whereColumn('sc_s.shipment_id', $prefix.'id');
            self::routeOfDriver($stop, 'sc_r', $driverId);
        })->orWhereExists(static function (Builder $delivery) use ($driverId, $prefix): void {
            $delivery->selectRaw('1')
                ->from('execution_deliveries as sc_d')
                ->join('execution_driver_routes as sc_v', 'sc_v.vehicle_id', '=', 'sc_d.vehicle_id')
                ->whereColumn('sc_d.shipment_id', $prefix.'id')
                ->whereIn('sc_v.status', self::ROUTE_OPEN);
            self::routeOfDriver($delivery, 'sc_v', $driverId);
        });
    }

    /**
     * La ruta (alias dado) es de ese chofer: el grabado en la ruta o, si la ruta no lo tiene, el de la
     * tripulación del vehículo para ese día.
     */
    private static function routeOfDriver(Builder $query, string $route, string $driverId): void
    {
        $query->where(static function (Builder $where) use ($route, $driverId): void {
            $where->where($route.'.driver_id', $driverId)
                ->orWhere(static function (Builder $fallback) use ($route, $driverId): void {
                    $fallback->whereNull($route.'.driver_id')->whereExists(static function (Builder $crew) use ($route, $driverId): void {
                        $crew->selectRaw('1')
                            ->from('fleet_crews as sc_c')
                            ->where('sc_c.driver_id', $driverId)
                            ->whereColumn('sc_c.vehicle_id', $route.'.vehicle_id')
                            ->whereColumn('sc_c.assembled_for', $route.'.route_date');
                    });
                });
        });
    }

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
     * Búsqueda por texto: código de pedido (`external_id` / `order_reference`) y, si `$customerData`, nombre
     * o documento del cliente. Sin distinguir mayúsculas; los comodines del usuario se escapan (busca el
     * texto literal). Con `$customerData` en false el texto nunca se compara contra datos del cliente.
     */
    public static function search(Builder $query, string $text, bool $customerData = true): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($text)).'%';

        // SQL literal por columna (nada dinámico en el texto de la consulta; el valor va por binding).
        $query->where(static function (Builder $where) use ($like, $customerData): void {
            $where->whereRaw('lower(external_id) like ?', [$like])
                ->orWhereRaw('lower(order_reference) like ?', [$like]);
            if ($customerData) {
                $where->orWhereRaw('lower(customer_name) like ?', [$like])
                    ->orWhereRaw('lower(customer_document_id) like ?', [$like]);
            }
        });
    }

    /**
     * Ciudad. Acepta dos formas del mismo parámetro:
     *  - un CÓDIGO de departamento del catálogo (`la_paz`, `santa_cruz`, …, tal cual, en minúsculas): filtra
     *    por igualdad sobre `destination_department` (columna indexada). Es lo que manda el selector de
     *    departamentos de la app. Un envío que todavía NO tiene departamento de destino resuelto entra si
     *    su ciudad o su dirección nombran ese departamento (no se esconde un pedido por un dato faltante);
     *  - cualquier otro texto: se filtra por la columna `city` (nombre canónico, indexada). El texto pedido
     *    se lleva a su nombre canónico cuando es una ciudad conocida («scz» → «Santa Cruz»). Para los envíos
     *    que todavía no tienen ciudad resuelta se conserva el respaldo: el texto dentro de la dirección. Los
     *    comodines del usuario se buscan como texto literal.
     *
     * `$prefix` califica las columnas cuando la consulta lee `shipments` con alias (p. ej. `sh.`).
     */
    public static function city(Builder $query, string $city, string $prefix = ''): void
    {
        $city = trim($city);
        if ($city === '') {
            return;
        }

        if (in_array($city, DepartmentCode::ALL, true)) {
            $code = $city;
            $query->where(static function (Builder $where) use ($code, $prefix): void {
                $where->where($prefix.'destination_department', $code)
                    ->orWhere(static function (Builder $unresolved) use ($code, $prefix): void {
                        $unresolved->whereNull($prefix.'destination_department');
                        self::cityText($unresolved, str_replace('_', ' ', $code), $prefix);
                    });
            });

            return;
        }

        self::cityText($query, $city, $prefix);
    }

    /**
     * Ciudad como TEXTO: coincide con la columna `city` o, si el envío no la tiene resuelta, con el texto de
     * la dirección. El alias es un literal del código (nunca texto del usuario); el valor va por binding.
     */
    private static function cityText(Builder $query, string $city, string $prefix): void
    {
        $canonical = AddressPartsView::canonicalCity($city) ?? $city;
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($canonical)).'%';

        $cityColumn = $prefix.'city';
        $addressColumn = $prefix.'address_text';

        // `ilike` de PostgreSQL: sin distinguir mayúsculas y sin SQL crudo (el valor va por binding).
        $query->where(static function (Builder $where) use ($like, $cityColumn, $addressColumn): void {
            $where->where($cityColumn, 'ilike', $like)
                ->orWhere(static function (Builder $unresolved) use ($like, $cityColumn, $addressColumn): void {
                    $unresolved->whereNull($cityColumn)->where($addressColumn, 'ilike', $like);
                });
        });
    }

    /**
     * Asignados a un vehículo: envíos con una entrega de ese camión o con una parada en una ruta suya. Lee
     * ejecución en solo lectura con EXISTS correlacionados por `shipment_id`.
     */
    public static function assignedToVehicle(Builder $query, string $vehicleId, string $prefix = 'shipments.'): void
    {
        $query->where(static function (Builder $where) use ($vehicleId, $prefix): void {
            $where->whereExists(static function (Builder $delivery) use ($vehicleId, $prefix): void {
                $delivery->selectRaw('1')
                    ->from('execution_deliveries as av_d')
                    ->whereColumn('av_d.shipment_id', $prefix.'id')
                    ->where('av_d.vehicle_id', $vehicleId);
            })->orWhereExists(static function (Builder $stop) use ($vehicleId, $prefix): void {
                $stop->selectRaw('1')
                    ->from('execution_driver_route_stops as av_s')
                    ->join('execution_driver_routes as av_r', 'av_r.id', '=', 'av_s.route_id')
                    ->whereColumn('av_s.shipment_id', $prefix.'id')
                    ->where('av_r.vehicle_id', $vehicleId);
            });
        });
    }

    /**
     * Listos para retirar: pedidos que el chofer ya DEJÓ en su tienda destino (entrega en tienda) y que
     * todavía no se entregaron ni se anularon. De respaldo cuenta también la recepción conciliada en esa
     * tienda (por si el chofer no registró su paso). Lee ejecución en solo lectura con EXISTS correlacionados
     * por `shipment_id`.
     */
    public static function readyForPickup(Builder $query, string $prefix = 'shipments.'): void
    {
        $query->whereNotIn($prefix.'status', [ShipmentStatus::Delivered->value, ShipmentStatus::Cancelled->value])
            ->where(static function (Builder $ready) use ($prefix): void {
                $ready->whereExists(static function (Builder $dropped) use ($prefix): void {
                    $dropped->selectRaw('1')
                        ->from('execution_deliveries as rp_d')
                        ->whereColumn('rp_d.shipment_id', $prefix.'id')
                        ->whereColumn('rp_d.store_drop_node_id', $prefix.'destination_node_id')
                        ->whereNotNull('rp_d.store_dropped_at');
                })->orWhereExists(static function (Builder $received) use ($prefix): void {
                    $received->selectRaw('1')
                        ->from('execution_node_receptions as rp_r')
                        ->whereColumn('rp_r.shipment_id', $prefix.'id')
                        ->whereColumn('rp_r.destination_node_id', $prefix.'destination_node_id')
                        ->where('rp_r.status', 'RECONCILED');
                });
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
