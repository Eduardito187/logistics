<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Deliveries;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\ScanEventRecord;

/**
 * App web (Inertia) — módulo Entregas. Monitor de solo lectura del read-side de execution: lista las
 * entregas (pedido, vehículo, estado, fecha de POD) y muestra el detalle de una entrega con su prueba
 * de entrega y la lista append-only de escaneos de terreno. Las operaciones de negocio siguen en el
 * admin/API; aquí nunca se escribe. Mismo patrón que RouteController del piloto.
 */
final class DeliveryController extends Controller
{
    /**
     * Listado de entregas en ejecución. Resuelve pedido (external_id) y vehículo (placa) legibles,
     * con eager-load para evitar N+1.
     */
    public function index(): Response
    {
        $deliveries = DeliveryRecord::query()
            ->with(['shipment', 'vehicle'])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (DeliveryRecord $delivery): array => [
                'id' => $delivery->getKey(),
                'order' => $delivery->shipment->external_id ?? '—',
                'customer' => $delivery->shipment->customer_name ?? '—',
                'vehicle' => $delivery->vehicle->plate ?? '—',
                'status' => (string) $delivery->status,
                'pod_captured_at' => $delivery->pod_captured_at,
            ])
            ->all();

        return Inertia::render('Deliveries/Index', [
            'deliveries' => $deliveries,
        ]);
    }

    /**
     * Detalle de una entrega: estado, prueba de entrega (confirmación, foto, hora), motivo de fallo si
     * aplica y la lista append-only de escaneos (tipo, unidad escaneada, hora, usuario, dispositivo,
     * lat/lng). Si hay escaneos con coordenadas, arma los puntos para el mapa opcional.
     */
    public function show(string $delivery): Response
    {
        $record = DeliveryRecord::query()
            ->with([
                'shipment',
                'vehicle',
                'scans' => static fn ($q) => $q->orderBy('occurred_at'),
            ])
            ->findOrFail($delivery);

        $scans = [];
        $mapStops = [];
        $track = [];
        $seq = 0;

        /** @var ScanEventRecord $scan */
        foreach ($record->scans as $scan) {
            $seq++;
            $lat = $this->coord($scan->lat);
            $lng = $this->coord($scan->lng);
            $hasPoint = $lat !== null && $lng !== null;

            $scans[] = [
                'id' => $scan->getKey(),
                'seq' => $seq,
                'type' => (string) $scan->type,
                // `scan_events.unit_id` es el CÓDIGO escaneado de la etiqueta (string), NO un UUID: se
                // muestra tal cual, sin armar relación por id (bug/contrato conocido del dominio).
                'unit' => $scan->unit_id,
                'occurred_at' => $scan->occurred_at,
                'user' => $scan->user_id,
                'device' => $scan->device_id,
                'lat' => $lat,
                'lng' => $lng,
                'has_point' => $hasPoint,
            ];

            if ($hasPoint) {
                $mapStops[] = [
                    'seq' => $seq,
                    'lat' => $lat,
                    'lng' => $lng,
                    'order' => $this->scanTypeLabel((string) $scan->type),
                    'customer' => $scan->unit_id,
                    'status' => (string) $scan->type,
                ];
                $track[] = [$lat, $lng];
            }
        }

        return Inertia::render('Deliveries/Show', [
            'delivery' => [
                'id' => $record->getKey(),
                'order' => $record->shipment->external_id ?? '—',
                'customer' => $record->shipment->customer_name ?? '—',
                'address' => $record->shipment->address_text ?? '—',
                'vehicle' => $record->vehicle->plate ?? '—',
                'status' => (string) $record->status,
                'pod_confirmation' => $record->pod_confirmation,
                'pod_photo_url' => $record->pod_photo_url,
                'pod_captured_at' => $record->pod_captured_at,
                'failure_reason' => $record->failure_reason,
            ],
            'scans' => $scans,
            'map' => [
                'stops' => $mapStops,
                // El recorrido real se traza con los puntos de los escaneos, en orden cronológico.
                'actual' => $track,
                'planned' => [],
            ],
        ]);
    }

    /**
     * Normaliza una coordenada a float, o null si no es válida.
     */
    private function coord(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Etiqueta legible del tipo de escaneo para el popup del mapa (UI en español).
     */
    private function scanTypeLabel(string $value): string
    {
        return match ($value) {
            'PREPARATION' => 'Preparación',
            'LOAD' => 'Carga',
            'UNLOAD' => 'Descarga',
            'PROOF_OF_DELIVERY' => 'Prueba de entrega',
            'NODE_RECEPTION' => 'Recepción en nodo',
            'IN_TRANSIT' => 'En tránsito',
            default => $value,
        };
    }
}
