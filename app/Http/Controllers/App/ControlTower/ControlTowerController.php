<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\ControlTower;

use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * App web (Inertia) — módulo Torre de control. Vista en vivo de la flota: cada vehículo con su estado,
 * sus entregas activas y su última posición conocida (derivada del tracking de los envíos que lleva).
 *
 * Solo lectura sobre el read-side Eloquent; nada de escrituras de negocio (eso sigue en admin/API).
 * Las consultas se hacen por lotes (sin N+1): vehículos, entregas activas y posiciones de tracking.
 */
final class ControlTowerController extends Controller
{
    /** Estados de entrega terminales: una entrega en estos estados ya no está "activa" en terreno. */
    private const TERMINAL_DELIVERY_STATUSES = [
        DeliveryStatus::Delivered->value,
        DeliveryStatus::Failed->value,
    ];

    /**
     * Pantalla de torre de control: flota, entregas activas por vehículo y posiciones para el mapa.
     */
    public function index(): Response
    {
        // 1) Flota completa con su nodo base legible (una sola consulta, sin N+1).
        $vehicles = VehicleModel::query()
            ->with('homeBaseNode')
            ->orderBy('plate')
            ->get();

        // 2) Entregas activas (no terminales) con el envío asociado, agrupadas por vehículo.
        $activeDeliveries = DeliveryRecord::query()
            ->with('shipment')
            ->whereNotIn('status', self::TERMINAL_DELIVERY_STATUSES)
            ->get()
            ->groupBy('vehicle_id');

        // 3) Última posición conocida por envío, desde el tracking (solo las que tienen coordenadas).
        $shipmentIds = $activeDeliveries
            ->flatten()
            ->pluck('shipment_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $positionsByShipment = TrackingRecord::query()
            ->whereIn('shipment_id', $shipmentIds)
            ->whereNotNull('last_lat')
            ->whereNotNull('last_lng')
            ->get()
            ->keyBy('shipment_id');

        $rows = [];
        $mapStops = [];
        $seq = 0;

        foreach ($vehicles as $vehicle) {
            /** @var Collection<int, DeliveryRecord> $deliveries */
            $deliveries = $activeDeliveries->get((string) $vehicle->getKey(), collect());

            $lat = null;
            $lng = null;
            $lastUpdate = null;

            foreach ($deliveries as $delivery) {
                $tracking = $positionsByShipment->get((string) $delivery->shipment_id);
                if (!$tracking instanceof TrackingRecord) {
                    continue;
                }

                $positionAt = $tracking->last_position_at?->toIso8601String();

                // Nos quedamos con la posición más reciente entre los envíos que lleva el vehículo.
                if ($lastUpdate === null || ($positionAt !== null && $positionAt > $lastUpdate)) {
                    $lat = $this->coord($tracking->last_lat);
                    $lng = $this->coord($tracking->last_lng);
                    $lastUpdate = $positionAt;
                }
            }

            $hasPoint = $lat !== null && $lng !== null;

            $rows[] = [
                'id' => (string) $vehicle->getKey(),
                'plate' => $vehicle->plate,
                'home_base' => $vehicle->homeBaseNode->name ?? '—',
                'status' => (string) $vehicle->status,
                'active_deliveries' => $deliveries->count(),
                'last_update' => $lastUpdate ?? '—',
                'has_point' => $hasPoint,
            ];

            if ($hasPoint) {
                $seq++;
                $mapStops[] = [
                    'seq' => $seq,
                    'lat' => $lat,
                    'lng' => $lng,
                    'order' => $vehicle->plate,
                    'customer' => $deliveries->count().' entregas activas',
                    'status' => $this->vehicleStatusLabel((string) $vehicle->status),
                ];
            }
        }

        return Inertia::render('ControlTower/Index', [
            'fleet' => $rows,
            'map' => [
                'stops' => $mapStops,
                'planned' => [],
                'actual' => [],
            ],
            'summary' => [
                'vehicles' => count($rows),
                'on_map' => count($mapStops),
                'active_deliveries' => $activeDeliveries->flatten()->count(),
            ],
        ]);
    }

    /**
     * Etiqueta legible del estado del vehículo (reutiliza las del dominio); el valor crudo si no mapea.
     */
    private function vehicleStatusLabel(string $status): string
    {
        $case = VehicleStatus::tryFrom($status);

        return $case?->label() ?? $status;
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
}
