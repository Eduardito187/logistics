<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Realtime;

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use stdClass;
use Throwable;

/**
 * Resuelve, para la señal `tracking.updated`, el estado VIGENTE del pedido, la referencia de su orden y las
 * DIMENSIONES de alcance a las que corresponde avisar: ciudades (departamento), nodos (tienda), chofer que lo
 * lleva hoy y transportista asignado. Solo lectura, por el query builder y con consultas baratas por índice;
 * corre en la cola, nunca en un request.
 *
 * El estado y la referencia salen del read model propio (`tracking_shipments`); las dimensiones del pedido se
 * leen de otros contextos en solo lectura, sin acoplar su dominio (igual que los demás lectores cross-context
 * del package):
 *  - ciudad y nodo: del envío en `shipments` (destino y origen).
 *  - chofer: de la ruta de ejecución vigente que transporta el envío (`execution_driver_route_stops` →
 *    `execution_driver_routes`), la más reciente.
 *  - transportista: del viaje de transportista que lleva el envío (`carrier_trip_shipments` → `carrier_trips`),
 *    el más reciente.
 *
 * Todo es TOLERANTE: una dimensión que no se puede leer (tabla ausente, dato vacío, forma inválida) se omite;
 * nunca lanza. Comparar un texto que no es UUID contra una columna `uuid` hace fallar la consulta en
 * PostgreSQL (22P02), así que se valida la forma antes de usar cada id.
 *
 * @phpstan-type TrackingSnapshot array{status: string, order_reference: string | null, cities: list<string>, nodes: list<string>, driver: string | null, carrier: string | null}
 */
final class TrackingSignalReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Foto del seguimiento del envío para la señal, o null si ya no existe (el seguimiento se borró o el id
     * no tiene forma de UUID: comparar otro texto contra una columna `uuid` hace fallar la consulta en
     * PostgreSQL).
     *
     * @return TrackingSnapshot|null
     */
    public function resolve(string $shipmentId): ?array
    {
        $id = self::uuid($shipmentId);
        if ($id === null) {
            return null;
        }

        $row = DB::table('tracking_shipments')->where('shipment_id', $id)->first(['status', 'order_reference']);
        $status = $row instanceof stdClass && is_string($row->status ?? null) ? trim($row->status) : '';
        if ($status === '') {
            return null;
        }

        $orderReference = $row instanceof stdClass && is_string($row->order_reference ?? null) ? trim($row->order_reference) : '';
        $shipment = $this->shipmentDimensions($id);

        return [
            'status' => $status,
            'order_reference' => $orderReference === '' ? null : $orderReference,
            'cities' => $shipment['cities'],
            'nodes' => $shipment['nodes'],
            'driver' => $this->driver($id),
            'carrier' => $this->carrier($id),
        ];
    }

    /**
     * Ciudades (departamento) y nodos (uuid) del envío, destino primero: arman los canales del tema por ciudad
     * y por nodo. Una sola lectura de `shipments`. Tolerante: si no se puede leer o no deja valores válidos,
     * va vacío (el cambio igual llega por el canal general, nunca se esconde).
     *
     * @return array{cities: list<string>, nodes: list<string>}
     */
    private function shipmentDimensions(string $shipmentId): array
    {
        try {
            $row = DB::table('shipments')
                ->where('id', $shipmentId)
                ->first(['destination_department', 'origin_department', 'destination_node_id', 'origin_node_id']);
        } catch (Throwable) {
            $row = null;
        }

        if (!$row instanceof stdClass) {
            return ['cities' => [], 'nodes' => []];
        }

        $cities = [];
        foreach ([$row->destination_department ?? null, $row->origin_department ?? null] as $value) {
            $code = RealtimeChannels::cityCode(is_string($value) ? $value : null);
            if ($code !== null && !in_array($code, $cities, true)) {
                $cities[] = $code;
            }
        }

        $nodes = [];
        foreach ([$row->destination_node_id ?? null, $row->origin_node_id ?? null] as $value) {
            $node = self::uuid(is_string($value) ? $value : '');
            if ($node !== null && !in_array($node, $nodes, true)) {
                $nodes[] = $node;
            }
        }

        return ['cities' => $cities, 'nodes' => $nodes];
    }

    /**
     * Chofer que lleva el envío HOY: el `driver_id` de la ruta de ejecución más reciente que lo contiene (una
     * parada por ruta). Tolerante: sin ruta, sin chofer poblado o si la consulta falla, devuelve null.
     */
    private function driver(string $shipmentId): ?string
    {
        try {
            $row = DB::table('execution_driver_route_stops as stops')
                ->join('execution_driver_routes as routes', 'routes.id', '=', 'stops.route_id')
                ->where('stops.shipment_id', $shipmentId)
                ->orderByDesc('routes.route_date')
                ->orderByDesc('routes.updated_at')
                ->first(['routes.driver_id']);
        } catch (Throwable) {
            $row = null;
        }

        return $row instanceof stdClass ? self::uuid(is_string($row->driver_id ?? null) ? $row->driver_id : '') : null;
    }

    /**
     * Transportista que lleva el envío: el `carrier_id` del viaje de transportista más reciente que lo carga.
     * Tolerante: sin viaje o si la consulta falla, devuelve null (se omite la dimensión).
     */
    private function carrier(string $shipmentId): ?string
    {
        try {
            $row = DB::table('carrier_trip_shipments as cargo')
                ->join('carrier_trips as trips', 'trips.id', '=', 'cargo.trip_id')
                ->where('cargo.shipment_id', $shipmentId)
                ->orderByDesc('trips.scheduled_at')
                ->first(['trips.carrier_id']);
        } catch (Throwable) {
            $row = null;
        }

        return $row instanceof stdClass ? self::uuid(is_string($row->carrier_id ?? null) ? $row->carrier_id : '') : null;
    }

    private static function uuid(string $value): ?string
    {
        return preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
