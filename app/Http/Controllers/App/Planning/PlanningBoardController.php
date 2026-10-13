<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Planning;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Planning\Application\Command\ReassignShipmentToVehicle\ReassignShipmentToVehicle;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlannedStopRecord;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\PlanRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * App web (Inertia) — Tablero del DÍA de Planificación. A diferencia del tablero de un solo plan
 * (Planning/Board, que reordena DENTRO de un plan), aquí se pintan TODOS los planes de una fecha como
 * columnas por camión y se habilita arrastrar paradas ENTRE columnas (mover un envío de un camión a
 * otro).
 *
 * `day` es solo lectura (lee el read-side de planning, igual que PlanningController). `reassign` es la
 * única escritura: traduce la petición al comando ReassignShipmentToVehicle y lo despacha por el
 * CommandBus (Presentation → Application), respetando las capas. Los errores de dominio (otro
 * departamento / sin capacidad) se muestran como flash de error, sin filtrar trazas.
 */
final class PlanningBoardController extends Controller
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * Tablero del día: una columna por camión (plan) de la fecha, con su placa, estado y paradas en orden.
     * La fecha llega en la URL ('Y-m-d'); si falta o es inválida, cae en hoy.
     */
    public function day(string $date): Response
    {
        $planDate = $this->normalizeDate($date);

        $records = PlanRecord::query()
            ->with(['vehicle', 'stops' => static fn ($q) => $q->with('shipment')->orderBy('sequence')])
            ->where('plan_date', $planDate)
            ->orderBy('vehicle_id')
            ->get();

        $columns = $records
            ->map(fn (PlanRecord $plan): array => $this->columnOf($plan))
            ->all();

        return Inertia::render('Planning/DayBoard', [
            'date' => $planDate,
            'columns' => $columns,
        ]);
    }

    /**
     * POST plans/reassign — mueve un envío de un plan a otro del mismo día (arrastre entre columnas).
     *
     * Arma el comando con los datos que el dominio necesita para validar las invariantes del plan de
     * destino: las coordenadas del envío que se mueve y las de las paradas de cliente que ya tiene el
     * destino (regla geográfica), y el cupo de paradas del vehículo de destino (regla de capacidad).
     * Esos datos los resuelve aquí leyendo el read-side; el dominio decide si la operación es válida.
     */
    public function reassign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shipment_id' => ['required', 'string', 'uuid'],
            'from_plan_id' => ['required', 'string', 'uuid'],
            'to_plan_id' => ['required', 'string', 'uuid', 'different:from_plan_id'],
        ]);

        $shipmentId = $validated['shipment_id'];
        $fromPlanId = $validated['from_plan_id'];
        $toPlanId = $validated['to_plan_id'];

        // Parada que se mueve (del plan de origen): de ahí salen la foto legible y las coordenadas del envío.
        $movingStop = PlannedStopRecord::query()
            ->with('shipment')
            ->where('plan_id', $fromPlanId)
            ->where('shipment_id', $shipmentId)
            ->first();

        if ($movingStop === null) {
            return back()->with('error', 'El envío no pertenece al plan de origen.');
        }

        // Plan de destino con su vehículo y sus paradas (para la regla geográfica y la de capacidad).
        $toPlan = PlanRecord::query()
            ->with(['vehicle', 'stops' => static fn ($q) => $q->with('shipment')])
            ->find($toPlanId);

        if ($toPlan === null) {
            return back()->with('error', 'No existe el plan de destino.');
        }
        assert($toPlan instanceof PlanRecord);

        $movingLat = $this->coord($movingStop->shipment?->lat);
        $movingLng = $this->coord($movingStop->shipment?->lng);
        $movingCustomer = $movingStop->customer_name ?? $movingStop->shipment?->customer_name;

        $command = new ReassignShipmentToVehicle(
            shipmentId: $shipmentId,
            fromPlanId: $fromPlanId,
            toPlanId: $toPlanId,
            orderReference: $movingStop->order_reference ?? $movingStop->shipment?->external_id,
            customerName: $movingCustomer,
            address: $movingStop->address ?? $movingStop->shipment?->address_text,
            latitude: $movingLat,
            longitude: $movingLng,
            destinationClientStops: $this->destinationClientStops($toPlan),
            toVehicleCapacity: $this->vehicleCapacity($toPlan),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Envío movido de camión.');
    }

    /**
     * Columna del tablero: cabecera del plan (placa, estado, nº de paradas, cupo) y sus paradas en orden.
     *
     * @return array<string, mixed>
     */
    private function columnOf(PlanRecord $plan): array
    {
        $stops = [];
        /** @var PlannedStopRecord $stop */
        foreach ($plan->stops as $stop) {
            $lat = $this->coord($stop->shipment?->lat);
            $lng = $this->coord($stop->shipment?->lng);

            $stops[] = [
                'seq' => (int) $stop->sequence,
                'shipment_id' => (string) $stop->shipment_id,
                'order' => $stop->order_reference ?? $stop->shipment->external_id ?? '—',
                'customer' => $stop->customer_name ?? $stop->shipment->customer_name ?? '—',
                'address' => $stop->address ?? $stop->shipment->address_text ?? '—',
                'lane' => $stop->lane,
                'eta' => $stop->eta,
                'eta_minutes' => $stop->eta_minutes,
                'has_point' => $lat !== null && $lng !== null,
            ];
        }

        return [
            'id' => $plan->getKey(),
            'vehicle' => $plan->vehicle->plate ?? '—',
            'status' => (string) $plan->status,
            'stops_count' => count($stops),
            'capacity' => $this->vehicleCapacity($plan),
            'stops' => $stops,
        ];
    }

    /**
     * Paradas de CLIENTE del plan de destino con sus coordenadas, para la política geográfica. Solo las
     * que entregan a un cliente y tienen ubicación (los traspasos CD→CD y las sin punto quedan fuera, igual
     * que en la asignación manual).
     *
     * @return list<ClientStopLocation>
     */
    private function destinationClientStops(PlanRecord $plan): array
    {
        $stops = [];
        /** @var PlannedStopRecord $stop */
        foreach ($plan->stops as $stop) {
            $lat = $this->coord($stop->shipment?->lat);
            $lng = $this->coord($stop->shipment?->lng);
            $customer = $stop->customer_name ?? $stop->shipment?->customer_name;

            if ($lat !== null && $lng !== null && $customer !== null) {
                $stops[] = ClientStopLocation::of((string) $stop->shipment_id, $lat, $lng);
            }
        }

        return $stops;
    }

    /**
     * Cupo de paradas del vehículo del plan: su volumen útil dividido por el volumen estimado de un bulto
     * (misma convención que el auto-planificador mientras Envíos no publique volumen real). null si no se
     * puede resolver (sin vehículo o sin capacidad): la regla de capacidad queda tolerante.
     */
    private function vehicleCapacity(PlanRecord $plan): ?int
    {
        $capacityM3 = $plan->vehicle?->capacity_m3;
        if (!is_numeric($capacityM3) || (float) $capacityM3 <= 0.0) {
            return null;
        }

        $perPackage = (float) config('logistics-planning.default_volume_m3_per_package', 0.05);
        if ($perPackage <= 0.0) {
            return null;
        }

        return (int) floor((float) $capacityM3 / $perPackage);
    }

    /**
     * Normaliza la fecha de la URL a 'Y-m-d'; cae en hoy si no es una fecha válida.
     */
    private function normalizeDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            [$y, $m, $d] = array_map(intval(...), explode('-', $date));
            if (checkdate($m, $d, $y)) {
                return $date;
            }
        }

        return Carbon::now()->format('Y-m-d');
    }

    /**
     * Normaliza una coordenada a float, o null si no es válida.
     */
    private function coord(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
