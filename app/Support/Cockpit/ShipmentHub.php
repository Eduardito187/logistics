<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Presentation\Support\DeliveryEvidenceGallery;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentLineView;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Tracking\Application\Contract\ReturnCoverageReader;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTracking;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnits;
use Logistics\Tracking\Application\ReadModel\ShipmentTrackingView;
use Logistics\Tracking\Application\ReadModel\UnitTrackingView;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un envío/pedido: agrega su ficha (cliente, dirección, ventana
 * comprometida, líneas), su seguimiento en vivo (estado, ETA, posición — contexto tracking), su entrega
 * (vehículo asignado + prueba de entrega, contexto execution) y sus bultos físicos. Prefiere los
 * QueryHandlers del QueryBus (CQRS) y cae a lectura directa solo donde no hay query (entrega por envío +
 * resolución de placa/nodo a texto legible). Tolerante: cada fuente en su try/catch. SOLO lectura; sin
 * UUID visible (la referencia es el nº de pedido, la placa, el nombre del nodo/cliente).
 */
final class ShipmentHub
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub del envío necesita, en primitivos y tolerante.
     *
     * @return array{
     *   shipment: array<string, mixed>|null,
     *   tracking: array{status: string, remaining_stops: int, route_position: int|null, eta: string|null, position_is_live: bool|null}|null,
     *   delivery: array{vehicle_plate: string|null, vehicle_id: string|null, status: string|null, pod_photo_url: string|null, pod_confirmation: string|null, pod_captured_at: string|null, failure_reason: string|null, fulfillment_mode: string|null, evidence: list<array{kind: string, url: string, captured_at: string|null, validation_status: string|null}>}|null,
     *   units: list<array{product_name: string, sku: string, status: string, piece: string|null, image_url: string|null}>,
     * }
     */
    public function overview(string $shipmentId): array
    {
        return [
            'shipment' => $this->shipment($shipmentId),
            'tracking' => $this->tracking($shipmentId),
            'delivery' => $this->delivery($shipmentId),
            'units' => $this->units($shipmentId),
        ];
    }

    /**
     * Ficha del envío (cliente, dirección, ventana, líneas, nodo destino legible), o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    private function shipment(string $shipmentId): ?array
    {
        try {
            $view = $this->queryBus->ask(new FindShipment($shipmentId));
            if (!$view instanceof ShipmentView) {
                return null;
            }

            $returned = $this->returnedBySku($view->externalId);

            $lines = [];
            foreach ($view->lines as $line) {
                if ($line instanceof ShipmentLineView) {
                    $lines[] = [
                        'returned' => min($line->quantity, $returned[$line->sku] ?? 0),
                        'sku' => $line->sku,
                        'product_name' => $line->productName,
                        'quantity' => $line->quantity,
                        'image_url' => $line->imageUrl,
                    ];
                }
            }

            return [
                'external_id' => $view->externalId,
                'source' => $view->source,
                'customer_name' => $view->customerName,
                'customer_phone' => $view->customerPhone,
                'customer_email' => $view->customerEmail,
                'customer_document' => $view->customerDocumentId,
                'address_text' => $view->addressText,
                'address_reference' => $view->addressReference,
                'confidence_level' => $view->addressConfidenceLevel,
                'confidence_score' => $view->addressConfidenceScore,
                'status' => $view->status,
                'delivery_date' => $view->deliveryDate,
                'window_start' => $view->deliveryWindowStart,
                'window_end' => $view->deliveryWindowEnd,
                'node_name' => $this->nodeName($view->destinationNodeId),
                'lines' => $lines,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Seguimiento en vivo del envío (estado, paradas restantes, posición en ruta, ETA), o null.
     *
     * @return array{status: string, remaining_stops: int, route_position: int|null, eta: string|null, position_is_live: bool|null}|null
     */
    private function tracking(string $shipmentId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetShipmentTracking($shipmentId));
            if (!$view instanceof ShipmentTrackingView) {
                return null;
            }

            return [
                'status' => $view->status,
                'remaining_stops' => $view->remainingStops,
                'route_position' => $view->routePosition,
                'eta' => $this->isoToShort($view->eta),
                'position_is_live' => $view->positionIsLive,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Entrega del envío (vehículo asignado + prueba de entrega), por lectura directa de execution_deliveries
     * (no hay query por envío). Null si todavía no hay una entrega creada.
     *
     * @return array{vehicle_plate: string|null, vehicle_id: string|null, status: string|null, pod_photo_url: string|null, pod_confirmation: string|null, pod_captured_at: string|null, failure_reason: string|null, fulfillment_mode: string|null, evidence: list<array{kind: string, url: string, captured_at: string|null, validation_status: string|null}>}|null
     */
    private function delivery(string $shipmentId): ?array
    {
        try {
            $row = DB::table('execution_deliveries')
                ->where('shipment_id', $shipmentId)
                ->orderByDesc('pod_captured_at')
                ->first(['vehicle_id', 'status', 'pod_photo_url', 'pod_confirmation', 'pod_captured_at', 'failure_reason', 'fulfillment_mode']);

            if ($row === null) {
                return null;
            }

            return [
                'vehicle_plate' => $this->vehiclePlate(is_string($row->vehicle_id) ? $row->vehicle_id : null),
                'vehicle_id' => is_string($row->vehicle_id) ? $row->vehicle_id : null,
                'status' => is_string($row->status) ? $row->status : null,
                'pod_photo_url' => is_string($row->pod_photo_url) && $row->pod_photo_url !== '' ? $row->pod_photo_url : null,
                'pod_confirmation' => is_string($row->pod_confirmation) && $row->pod_confirmation !== '' ? $row->pod_confirmation : null,
                'pod_captured_at' => $this->isoToShort(is_string($row->pod_captured_at) ? $row->pod_captured_at : null),
                'failure_reason' => is_string($row->failure_reason) && $row->failure_reason !== '' ? $row->failure_reason : null,
                'fulfillment_mode' => is_string($row->fulfillment_mode) ? $row->fulfillment_mode : null,
                'evidence' => $this->evidence($shipmentId),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Evidencia visible de la entrega (firma del cliente, fotos, documento) con URL temporal. Tolerante.
     *
     * @return list<array{kind: string, url: string, captured_at: string|null, validation_status: string|null}>
     */
    private function evidence(string $shipmentId): array
    {
        try {
            return (new DeliveryEvidenceGallery($this->queryBus))->forShipment($shipmentId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Bultos físicos del envío con su producto legible (sku, nombre, pieza), o lista vacía.
     *
     * @return list<array{product_name: string, sku: string, status: string, piece: string|null, image_url: string|null}>
     */
    private function units(string $shipmentId): array
    {
        try {
            /** @var list<UnitTrackingView> $views */
            $views = $this->queryBus->ask(new GetShipmentUnits($shipmentId));
            $out = [];
            foreach ($views as $view) {
                $piece = $view->pieceIndex !== null && $view->pieceTotal !== null
                    ? $view->pieceIndex.'/'.$view->pieceTotal
                    : null;

                $out[] = [
                    'product_name' => $view->productName,
                    'sku' => $view->sku,
                    'status' => $view->status,
                    'piece' => $piece,
                    'image_url' => $view->productImageUrl,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Cantidad ya devuelta de cada producto del pedido (devoluciones parciales). Tolerante: vacío si falla.
     *
     * @return array<string, int>
     */
    private function returnedBySku(?string $externalId): array
    {
        if ($externalId === null || $externalId === '') {
            return [];
        }

        try {
            $out = [];
            foreach (app(ReturnCoverageReader::class)->coverage(explode('#n:', $externalId)[0]) as $sku => $line) {
                $out[(string) $sku] = $line['returned'];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** Nombre legible del nodo destino (network_nodes.name), o null. Lectura tolerante. */
    private function nodeName(?string $nodeId): ?string
    {
        if ($nodeId === null || $nodeId === '') {
            return null;
        }

        try {
            $name = DB::table('network_nodes')->where('id', $nodeId)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Placa legible del vehículo (fleet_vehicles.plate), o null. Lectura tolerante. */
    private function vehiclePlate(?string $vehicleId): ?string
    {
        if ($vehicleId === null || $vehicleId === '') {
            return null;
        }

        try {
            $plate = DB::table('fleet_vehicles')->where('id', $vehicleId)->value('plate');

            return is_string($plate) && $plate !== '' ? $plate : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** ISO 8601 → "19 oct 09:00" legible, o null si viene vacío/ilegible. */
    private function isoToShort(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM HH:mm');
        } catch (Throwable) {
            return null;
        }
    }
}
