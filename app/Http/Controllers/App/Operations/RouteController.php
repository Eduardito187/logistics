<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Operations;

use App\Http\Controllers\App\Concerns\ResolvesRouteMapData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\RouteStopRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\VehicleRouteRecord;

/**
 * App web (Inertia) — módulo Operaciones/Rutas. Lee el read-side de execution para listar las rutas de
 * reparto y mostrar su detalle con mapa en vivo y paradas. Solo lectura; las operaciones de negocio
 * siguen en el admin/API. Patrón de referencia para los demás módulos del SPA.
 */
final class RouteController extends Controller
{
    use ResolvesRouteMapData;

    /**
     * Listado de rutas de reparto.
     */
    public function index(): Response
    {
        $routes = VehicleRouteRecord::query()
            ->with('vehicle')
            ->withCount('stops')
            ->orderByDesc('route_date')
            ->limit(100)
            ->get()
            ->map(static fn (VehicleRouteRecord $route): array => [
                'id' => $route->getKey(),
                'route_date' => $route->route_date,
                'vehicle' => $route->vehicle->plate ?? '—',
                'status' => (string) $route->status,
                'stops_count' => (int) $route->stops_count,
            ])
            ->all();

        return Inertia::render('Operations/Routes/Index', [
            'routes' => $routes,
        ]);
    }

    /**
     * Detalle de una ruta: cabecera, paradas en orden y datos del mapa (paradas + trazado planificado).
     */
    public function show(Request $request, string $route): Response
    {
        $record = VehicleRouteRecord::query()
            ->with(['vehicle', 'stops' => static fn ($q) => $q->with('shipment')->orderBy('sequence')])
            ->findOrFail($route);

        $stops = [];
        $mapStops = [];
        $planned = [];
        $shipmentIds = [];

        /** @var RouteStopRecord $stop */
        foreach ($record->stops as $stop) {
            $lat = $this->coord($stop->lat ?? $stop->shipment?->lat);
            $lng = $this->coord($stop->lng ?? $stop->shipment?->lng);
            $hasPoint = $lat !== null && $lng !== null;

            if ($stop->shipment_id !== null) {
                $shipmentIds[] = (string) $stop->shipment_id;
            }

            $stops[] = [
                'seq' => (int) $stop->sequence,
                'status' => (string) $stop->status,
                'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                'address' => $stop->address ?? $stop->shipment->address_text ?? '—',
                'eta' => $stop->eta,
                'has_point' => $hasPoint,
            ];

            if ($hasPoint) {
                $mapStops[] = [
                    'seq' => (int) $stop->sequence,
                    'lat' => $lat,
                    'lng' => $lng,
                    'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                    'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                    'status' => (string) $stop->status,
                ];
                $planned[] = [$lat, $lng];
            }
        }

        return Inertia::render('Operations/Routes/Show', [
            'route' => [
                'id' => $record->getKey(),
                'route_date' => $record->route_date,
                'vehicle' => $record->vehicle->plate ?? '—',
                'status' => (string) $record->status,
            ],
            'stops' => $stops,
            'map' => [
                'stops' => $mapStops,
                'planned' => $planned,
                // Geometría planificada persistida por Planning (sigue calles); si no hay, el mapa cae a OSRM.
                'geometry' => $this->plannedGeometryForVehicleDay((string) $record->vehicle_id, (string) $record->route_date),
                // Recorrido real del camión: puntos GPS de tránsito (escaneos IN_TRANSIT) de esta ruta.
                'actual' => $this->actualTrackForShipments((string) $record->vehicle_id, array_values(array_unique($shipmentIds))),
                'osrmHost' => $this->osrmHost(),
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
}
