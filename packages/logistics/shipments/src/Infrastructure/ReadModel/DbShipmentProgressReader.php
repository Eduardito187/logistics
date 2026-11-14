<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\ReadModel\ShipmentProgressView;
use Throwable;

/**
 * Implementación SQL del avance operativo de los envíos (enriquecimiento de lectura del listado de la app).
 *
 * Lectura cruzada de SOLO LECTURA (por el query builder, sin tocar los dominios ajenos) de:
 *  - execution_deliveries + execution_scan_events → vehículo real, estado de la entrega y horas de
 *    preparado (primer escaneo PREPARATION), cargado (primer LOAD) y entregado (POD);
 *  - execution_driver_route_stops + execution_driver_routes → camión asignado y hora de asignación;
 *  - planning_plan_stops + planning_plans (solo PUBLICADOS) → camión del plan si aún no hay ruta;
 *  - fleet_vehicles → patente del camión;
 *  - labels_emitted → etiquetas emitidas del PEDIDO (por order_reference; es un conteo por orden).
 *
 * Costo fijo por página: UNA consulta por fuente (whereIn por lote de ids), nunca una por envío. Cada
 * fuente va aislada en su try/catch: si una tabla no está disponible, solo sus campos quedan null.
 *
 * Prioridad del camión: el de la entrega (ejecución real) > el de la ruta del chofer > el del plan.
 */
final readonly class DbShipmentProgressReader implements ShipmentProgressReader
{
    /** Tope de ids por whereIn (acota el tamaño de la sentencia cuando no se pagina). */
    private const BATCH = 1000;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipmentIds(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($ids, self::BATCH) as $batch) {
            $result += $this->forBatch($batch);
        }

        return $result;
    }

    /**
     * @param  list<string>                        $ids
     * @return array<string, ShipmentProgressView>
     */
    private function forBatch(array $ids): array
    {
        $deliveries = $this->deliveries($ids);
        $routes = $this->routeAssignments($ids);
        $plans = $this->planAssignments($ids);
        $orderRefs = $this->orderReferences($ids);
        $labels = $this->labelCounts(array_values(array_unique(array_values($orderRefs))));
        $readyForPickup = $this->readyForPickup($ids);

        $vehicleIds = [];
        foreach ($ids as $id) {
            $vehicleId = $deliveries[$id]['vehicle_id'] ?? $routes[$id]['vehicle_id'] ?? $plans[$id]['vehicle_id'] ?? null;
            if ($vehicleId !== null) {
                $vehicleIds[$id] = $vehicleId;
            }
        }
        $plates = $this->plates(array_values(array_unique(array_values($vehicleIds))));

        $views = [];
        foreach ($ids as $id) {
            $delivery = $deliveries[$id] ?? null;
            $vehicleId = $vehicleIds[$id] ?? null;
            $orderRef = $orderRefs[$id] ?? null;

            $views[$id] = new ShipmentProgressView(
                vehicleId: $vehicleId,
                plate: $vehicleId !== null ? ($plates[$vehicleId] ?? null) : null,
                assignedAt: $routes[$id]['assigned_at'] ?? $plans[$id]['assigned_at'] ?? null,
                preparedAt: $delivery['prepared_at'] ?? null,
                loadedAt: $delivery['loaded_at'] ?? null,
                deliveredAt: $delivery['delivered_at'] ?? null,
                labelsEmittedCount: $labels === null || $orderRef === null ? null : ($labels[$orderRef] ?? 0),
                executionStatus: $delivery['status'] ?? null,
                readyForPickupAt: $readyForPickup[$id] ?? null,
            );
        }

        return $views;
    }

    /**
     * Entrega en ejecución de cada envío, con las horas de sus escaneos clave (una consulta agregada).
     *
     * @param  list<string>                                                                                                                                    $ids
     * @return array<string, array{vehicle_id: string|null, status: string|null, prepared_at: string|null, loaded_at: string|null, delivered_at: string|null}>
     */
    private function deliveries(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_deliveries as d')
                ->leftJoin('execution_scan_events as e', 'e.delivery_id', '=', 'd.id')
                ->whereIn('d.shipment_id', $ids)
                ->groupBy('d.id', 'd.shipment_id', 'd.vehicle_id', 'd.status', 'd.pod_captured_at', 'd.updated_at', 'd.created_at')
                ->selectRaw(
                    'd.shipment_id, d.vehicle_id, d.status, d.pod_captured_at, d.updated_at, '
                    // Preparado: el escaneo de PREPARACIÓN si existe (flujo viejo), o la apertura de la entrega
                    // (`d.created_at` = cuando se asignó el vehículo, que en el flujo vigente ES preparar). Sin
                    // el COALESCE, prepared_at siempre sería null porque el escaneo se eliminó del flujo.
                    ."COALESCE(MIN(CASE WHEN e.type = 'PREPARATION' THEN e.occurred_at END), d.created_at) AS prepared_at, "
                    ."MIN(CASE WHEN e.type = 'LOAD' THEN e.occurred_at END) AS loaded_at, "
                    ."MAX(CASE WHEN e.type = 'PROOF_OF_DELIVERY' THEN e.occurred_at END) AS pod_scan_at",
                )
                ->get();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $shipmentId = self::str($row->shipment_id ?? null);
            if ($shipmentId === null) {
                continue;
            }

            $status = self::str($row->status ?? null);
            $deliveredAt = null;
            if ($status === 'DELIVERED') {
                $deliveredAt = self::iso($row->pod_captured_at ?? null)
                    ?? self::iso($row->pod_scan_at ?? null)
                    ?? self::iso($row->updated_at ?? null);
            }

            $result[$shipmentId] = [
                'vehicle_id' => self::str($row->vehicle_id ?? null),
                'status' => $status,
                'prepared_at' => self::iso($row->prepared_at ?? null),
                'loaded_at' => self::iso($row->loaded_at ?? null),
                'delivered_at' => $deliveredAt,
            ];
        }

        return $result;
    }

    /**
     * Última asignación del envío a la ruta de un camión (manual o armada desde el plan).
     *
     * @param  list<string>                                                            $ids
     * @return array<string, array{vehicle_id: string|null, assigned_at: string|null}>
     */
    private function routeAssignments(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_driver_route_stops as s')
                ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
                ->whereIn('s.shipment_id', $ids)
                ->orderBy('s.created_at')
                ->get(['s.shipment_id', 'r.vehicle_id', 's.created_at']);
        } catch (Throwable) {
            return [];
        }

        // Orden ascendente: la última fila de cada envío (la asignación más reciente) es la que queda.
        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $shipmentId = self::str($row->shipment_id ?? null);
            if ($shipmentId !== null) {
                $result[$shipmentId] = [
                    'vehicle_id' => self::str($row->vehicle_id ?? null),
                    'assigned_at' => self::iso($row->created_at ?? null),
                ];
            }
        }

        return $result;
    }

    /**
     * Camión del plan PUBLICADO más reciente que incluye al envío (antes de armarse la ruta del chofer).
     *
     * @param  list<string>                                                            $ids
     * @return array<string, array{vehicle_id: string|null, assigned_at: string|null}>
     */
    private function planAssignments(array $ids): array
    {
        try {
            $rows = $this->connection->table('planning_plan_stops as s')
                ->join('planning_plans as p', 'p.id', '=', 's.plan_id')
                ->whereIn('s.shipment_id', $ids)
                ->where('p.status', 'PUBLISHED')
                ->orderBy('p.updated_at')
                ->get(['s.shipment_id', 'p.vehicle_id', 'p.updated_at']);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $shipmentId = self::str($row->shipment_id ?? null);
            if ($shipmentId !== null) {
                $result[$shipmentId] = [
                    'vehicle_id' => self::str($row->vehicle_id ?? null),
                    'assigned_at' => self::iso($row->updated_at ?? null),
                ];
            }
        }

        return $result;
    }

    /**
     * Referencia de la orden de cada envío (order_reference, o external_id en filas viejas).
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function orderReferences(array $ids): array
    {
        try {
            $rows = $this->connection->table('shipments')
                ->whereIn('id', $ids)
                ->get(['id', 'order_reference', 'external_id']);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $id = self::str($row->id ?? null);
            $ref = self::str($row->order_reference ?? null) ?? self::str($row->external_id ?? null);
            if ($id !== null && $ref !== null) {
                $result[$id] = $ref;
            }
        }

        return $result;
    }

    /**
     * Etiquetas emitidas (no revocadas) por pedido. Null si la fuente no está disponible (desconocido).
     *
     * @param  list<string>            $orderRefs
     * @return array<string, int>|null
     */
    private function labelCounts(array $orderRefs): ?array
    {
        if ($orderRefs === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('labels_emitted')
                ->whereIn('order_id', $orderRefs)
                ->whereNull('revoked_at')
                ->groupBy('order_id')
                ->selectRaw('order_id, COUNT(*) AS total')
                ->get();
        } catch (Throwable) {
            return null;
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $ref = self::str($row->order_id ?? null);
            if ($ref !== null) {
                $result[$ref] = is_numeric($row->total ?? null) ? (int) $row->total : 0;
            }
        }

        return $result;
    }

    /**
     * Cuándo quedó cada envío en su tienda DESTINO listo para retirar: la conciliación de su recepción en
     * ese nodo (execution_node_receptions). Solo los que ya la tienen. Tolerante: vacío si la fuente falla.
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function readyForPickup(array $ids): array
    {
        try {
            $rows = $this->connection->table('execution_node_receptions as r')
                ->join('shipments as s', 's.id', '=', 'r.shipment_id')
                ->whereIn('r.shipment_id', $ids)
                ->whereColumn('r.destination_node_id', 's.destination_node_id')
                ->where('r.status', 'RECONCILED')
                ->groupBy('r.shipment_id')
                ->selectRaw('r.shipment_id, MIN(r.reconciled_at) AS ready_at')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $shipmentId = self::str($row->shipment_id ?? null);
            $readyAt = self::iso($row->ready_at ?? null);
            if ($shipmentId !== null && $readyAt !== null) {
                $result[$shipmentId] = $readyAt;
            }
        }

        return $result;
    }

    /**
     * Patente de cada camión (fleet_vehicles.plate) por id.
     *
     * @param  list<string>          $vehicleIds
     * @return array<string, string>
     */
    private function plates(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('fleet_vehicles')
                ->whereIn('id', $vehicleIds)
                ->get(['id', 'plate']);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $id = self::str($row->id ?? null);
            $plate = self::str($row->plate ?? null);
            if ($id !== null && $plate !== null) {
                $result[$id] = $plate;
            }
        }

        return $result;
    }

    /** Normaliza a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** Normaliza una marca de tiempo de la base a ISO 8601 (ATOM), o null si no es interpretable. */
    private static function iso(mixed $value): ?string
    {
        $raw = self::str($value);
        if ($raw === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($raw))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
