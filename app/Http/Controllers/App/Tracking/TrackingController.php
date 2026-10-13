<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Tracking;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * App web (Inertia) — módulo Seguimiento. Lee el read model de tracking (tabla tracking_shipments) para
 * listar los seguimientos de envíos y mostrar su detalle: estado visible, ETA, paradas restantes, última
 * posición GPS y un mapa. Solo lectura; las operaciones de negocio siguen en el admin/API.
 *
 * DATOS LEGIBLES: el envío se muestra por su referencia humana (external_id + cliente) vía la relación
 * Eloquent shipment(), nunca por el UUID crudo de shipment_id. El token público JAMÁS se expone crudo:
 * se enmascara. Las fechas (eta, last_position_at) están casteadas a Carbon y se formatean a texto.
 */
final class TrackingController extends Controller
{
    /**
     * Listado de seguimientos de envíos.
     */
    public function index(): Response
    {
        $trackings = TrackingRecord::query()
            ->with('shipment')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(static function (TrackingRecord $record): array {
                $shipment = $record->shipment;

                return [
                    'id' => $record->getKey(),
                    'order' => $shipment instanceof ShipmentRecord ? $shipment->external_id : '—',
                    'customer' => $shipment instanceof ShipmentRecord ? $shipment->customer_name : '—',
                    'status' => (string) $record->status,
                    'eta' => self::formatDate($record->eta),
                    'remaining_stops' => (int) $record->remaining_stops,
                ];
            })
            ->all();

        return Inertia::render('Tracking/Index', [
            'trackings' => $trackings,
        ]);
    }

    /**
     * Detalle de un seguimiento: estado visible, ETA, paradas restantes, última posición GPS y mapa.
     */
    public function show(string $tracking): Response
    {
        $record = TrackingRecord::query()
            ->with('shipment')
            ->findOrFail($tracking);

        $lat = $this->coord($record->last_lat);
        $lng = $this->coord($record->last_lng);
        $hasPoint = $lat !== null && $lng !== null;

        $shipment = $record->shipment;
        $order = $shipment instanceof ShipmentRecord ? $shipment->external_id : '—';
        $customer = $shipment instanceof ShipmentRecord ? $shipment->customer_name : '—';
        $address = $shipment instanceof ShipmentRecord ? $shipment->address_text : '—';

        // Si hay última posición GPS, se pasa como una única "parada" al mapa reutilizable (RouteMap).
        // El trazado planificado queda vacío: el seguimiento no tiene geometría de ruta, solo el punto.
        $mapStops = $hasPoint
            ? [[
                'seq' => 1,
                'lat' => $lat,
                'lng' => $lng,
                'order' => $order,
                'customer' => $customer,
                'status' => (string) $record->status,
            ]]
            : [];

        return Inertia::render('Tracking/Show', [
            'tracking' => [
                'id' => $record->getKey(),
                'order' => $order,
                'customer' => $customer,
                'address' => $address,
                'status' => (string) $record->status,
                'eta' => self::formatDate($record->eta),
                'remaining_stops' => (int) $record->remaining_stops,
                'route_position' => $record->route_position,
                // El token público nunca se muestra crudo: se enmascara (solo los últimos 4 caracteres).
                'token_masked' => self::maskToken($record->token),
                'last_lat' => $lat,
                'last_lng' => $lng,
                'last_position_at' => self::formatDate($record->last_position_at),
                'has_point' => $hasPoint,
            ],
            'map' => [
                'stops' => $mapStops,
                'planned' => [],
                'actual' => [],
            ],
        ]);
    }

    /**
     * Normaliza una coordenada (string|null del read model) a float, o null si no es válida.
     */
    private function coord(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * Formatea un instante Carbon a texto legible, o null si no hay dato.
     */
    private static function formatDate(?Carbon $value): ?string
    {
        return $value?->format('Y-m-d H:i');
    }

    /**
     * Enmascara el token público del enlace de rastreo: nunca se muestra crudo. Deja ver solo los
     * últimos cuatro caracteres como pista de verificación.
     */
    private static function maskToken(?string $token): string
    {
        if ($token === null || $token === '') {
            return '—';
        }

        return '••••••'.substr($token, -4);
    }
}
