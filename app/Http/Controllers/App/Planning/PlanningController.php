<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Planning;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;

/**
 * App web (Inertia) — módulo Planificación. Lee el read-side de planning para listar los planes de
 * reparto y mostrar el detalle de un plan con sus paradas en orden y un mapa (paradas geolocalizadas +
 * trazado planificado). Solo lectura; la generación/ajuste/publicación del plan sigue en el admin/API.
 *
 * Replica el patrón del módulo piloto (Operaciones/Rutas): resuelve datos humanos (placa del camión,
 * referencia del pedido, cliente) en lugar de UUID crudos y evita N+1 con eager-loading.
 */
final class PlanningController extends Controller
{
    /**
     * Listado de planes de reparto (fecha, vehículo/placa, nº de paradas, estado).
     */
    public function index(): Response
    {
        $plans = PlanRecord::query()
            ->with('vehicle')
            ->withCount('stops')
            ->orderByDesc('plan_date')
            ->limit(100)
            ->get()
            ->map(static fn (PlanRecord $plan): array => [
                'id' => $plan->getKey(),
                'plan_date' => $plan->plan_date,
                'vehicle' => $plan->vehicle->plate ?? '—',
                'status' => (string) $plan->status,
                'stops_count' => (int) $plan->stops_count,
            ])
            ->all();

        return Inertia::render('Planning/Index', [
            'plans' => $plans,
        ]);
    }

    /**
     * Detalle de un plan: cabecera, paradas en orden y datos del mapa (paradas + trazado planificado).
     */
    public function show(Request $request, string $plan): Response
    {
        return Inertia::render('Planning/Show', $this->planProps($plan));
    }

    /**
     * Tablero interactivo del plan (fase 2): mismos datos que el detalle, pero la página habilita el
     * reordenamiento por arrastre de las paradas y las acciones de escritura (publicar / auto-planificar).
     */
    public function board(Request $request, string $plan): Response
    {
        return Inertia::render('Planning/Board', $this->planProps($plan));
    }

    /**
     * Arma las props de lectura de un plan (cabecera, paradas en orden y datos del mapa). Lo comparten el
     * detalle de solo lectura y el tablero interactivo; incluye el `shipment_id` de cada parada porque el
     * reordenamiento se persiste por lista de envíos (comando ReorderStops), no por secuencia cruda.
     *
     * @return array<string, mixed>
     */
    private function planProps(string $plan): array
    {
        $record = PlanRecord::query()
            ->with(['vehicle', 'stops' => static fn ($q) => $q->with('shipment')->orderBy('sequence')])
            ->findOrFail($plan);

        $stops = [];
        $mapStops = [];
        $planned = [];

        /** @var PlannedStopRecord $stop */
        foreach ($record->stops as $stop) {
            // Las coordenadas de la parada las aporta el envío (planning no las guarda en la parada).
            $lat = $this->coord($stop->shipment?->lat);
            $lng = $this->coord($stop->shipment?->lng);
            $hasPoint = $lat !== null && $lng !== null;

            $stops[] = [
                'seq' => (int) $stop->sequence,
                // Identidad del envío: la clave que consume el reordenamiento por arrastre.
                'shipment_id' => (string) $stop->shipment_id,
                'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                'address' => $stop->address ?? $stop->shipment->address_text ?? '—',
                'eta' => $stop->eta,
                'eta_minutes' => $stop->eta_minutes,
                'lane' => $stop->lane,
                'has_point' => $hasPoint,
            ];

            if ($hasPoint) {
                $mapStops[] = [
                    'seq' => (int) $stop->sequence,
                    'lat' => $lat,
                    'lng' => $lng,
                    'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                    'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                    'status' => (string) $record->status,
                ];
                // Trazado planificado: las paradas en orden de secuencia (mismo criterio que el piloto).
                $planned[] = [$lat, $lng];
            }
        }

        return [
            'plan' => [
                'id' => $record->getKey(),
                'plan_date' => $record->plan_date,
                'vehicle' => $record->vehicle->plate ?? '—',
                'status' => (string) $record->status,
                'stops_count' => count($stops),
            ],
            'stops' => $stops,
            'map' => [
                'stops' => $mapStops,
                'planned' => $planned,
                'actual' => [],
            ],
        ];
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
