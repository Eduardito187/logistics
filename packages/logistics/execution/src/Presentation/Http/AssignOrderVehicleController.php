<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use App\Support\Cockpit\LabelLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\DetachShipmentFromRoute\DetachShipmentFromRoute;
use Logistics\Execution\Application\Command\OpenDelivery\OpenDelivery;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;
use Logistics\Execution\Presentation\Http\Request\AssignOrderVehicleRequest;
use Throwable;

/**
 * POST /app/orders/{orderNumber}/vehicle — asigna el pedido a un camión de reparto REAL y lo ATA a la
 * ruta de ese vehículo (puerta app). Antes, la selección de camión vivía solo en el cliente; ahora el
 * pedido aparece en «Mi ruta de hoy» del chofer del camión y queda listo para el POD.
 *
 * Valida (cross-context, lectura directa fuera del dominio como el resto del admin): que el pedido exista
 * (404) y que el vehículo exista y esté OPERATIVO (422 si no, nunca 500). Un vehículo NO APTO (checklist
 * pre-viaje con ítem crítico fallido y no liberado) se RECHAZA con 422 explícito; no se filtra en silencio. Una orden puede tener varios
 * envíos (multidestino): se asignan todos al mismo camión. Idempotente; permite reasignar.
 */
final class AssignOrderVehicleController extends ExecutionController
{
    /** Estados de vehículo que pueden salir a reparto (coinciden con el selector /app/fleet/vehicles). */
    private const array OPERATIVE = ['available', 'in_service'];

    public function __invoke(AssignOrderVehicleRequest $request, string $orderNumber): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $vehicleId = (string) $data['vehicle_id'];

        $shipments = $this->shipmentsOf($orderNumber);
        if ($shipments === []) {
            return ProblemDetails::fromCode(
                ErrorCode::NotFound,
                "No order found for reference {$orderNumber}.",
            )->toResponse($request);
        }

        $vehicle = $this->operativeVehicle($vehicleId);
        if ($vehicle === null) {
            return ProblemDetails::fromCode(
                ErrorCode::BusinessRuleViolation,
                'The selected vehicle does not exist or is not available for dispatch.',
            )->toResponse($request);
        }

        // Aptitud del pre-viaje (Flota): un vehículo no apto se rechaza con su motivo, nunca se omite.
        if (app(VehicleFitnessReader::class)->isUnfit($vehicleId)) {
            return ProblemDetails::fromCode(
                ErrorCode::BusinessRuleViolation,
                VehicleFitnessReader::UNFIT_DETAIL,
            )->toResponse($request);
        }

        $shipmentIds = array_values(array_map(static fn (\stdClass $s): string => (string) $s->id, $shipments));
        // Vehículo de la entrega ANTES de asignar: permite detectar una reasignación REAL (el dominio es la
        // fuente de verdad del movimiento; acá solo se observa el cambio para auditarlo).
        $vehiclesBefore = $this->deliveryVehicles($shipmentIds);

        $assigned = [];
        foreach ($shipments as $shipment) {
            $shipmentId = (string) $shipment->id;

            $this->commandBus->dispatch(new AttachShipmentToRoute(
                vehicleId: $vehicleId,
                shipmentId: $shipmentId,
                orderReference: $orderNumber,
                customerName: is_string($shipment->customer_name ?? null) ? $shipment->customer_name : null,
                address: is_string($shipment->address_text ?? null) ? $shipment->address_text : null,
            ));

            // Flujo nuevo del dueño: asignar el vehículo ES preparar (ya no hay escaneo de preparación).
            // Abrir la entrega acá deja al chofer habilitado para cargar y registrar el POD; sin esto, el
            // POD falla en dominio ("no hay entrega abierta"). Si ya existía y sigue en curso, la MUEVE al
            // camión nuevo; una entrega ya cerrada (POD hecho) no se mueve. Idempotente.
            $this->commandBus->dispatch(new OpenDelivery($shipmentId, $vehicleId));

            // Si el envío estaba en la ruta de OTRO camión, se lo saca de allí (su hoja de ruta debe quedar
            // consistente con la entrega, que ya se movió). El dominio solo desengancha paradas aún
            // pendientes: una ya atendida en el camión viejo se respeta. Idempotente.
            $previousVehicle = $vehiclesBefore[$shipmentId] ?? '';
            if ($previousVehicle !== '' && $previousVehicle !== $vehicleId) {
                $this->commandBus->dispatch(new DetachShipmentFromRoute($previousVehicle, $shipmentId));
            }

            $assigned[] = ['shipment_id' => $shipmentId, 'status' => (string) $shipment->status];
        }

        $this->auditReassignmentIfMoved($request, $orderNumber, $shipmentIds, $vehiclesBefore, $vehicleId, (string) $vehicle->plate);

        return new JsonResponse([
            'data' => [
                'order_reference' => $orderNumber,
                'vehicle_id' => $vehicleId,
                'plate' => (string) $vehicle->plate,
                'shipments' => $assigned,
            ],
        ]);
    }

    /**
     * Envíos del pedido (uno por destino) por su referencia. Lectura cross-context directa a `shipments`.
     *
     * @return list<\stdClass>
     */
    private function shipmentsOf(string $orderNumber): array
    {
        return array_values(
            DB::table('shipments')
                ->where('order_reference', $orderNumber)
                ->orderBy('id')
                ->get(['id', 'customer_name', 'address_text', 'status'])
                ->all(),
        );
    }

    /**
     * Vehículo operativo por id (existe y en estado de reparto), o null. Lectura directa a `fleet_vehicles`.
     */
    private function operativeVehicle(string $vehicleId): ?\stdClass
    {
        return DB::table('fleet_vehicles')
            ->where('id', $vehicleId)
            ->whereIn('status', self::OPERATIVE)
            ->first(['id', 'plate', 'status']);
    }

    /**
     * Vehículo actual de la entrega de cada envío (shipmentId → vehicle_id), para detectar reasignación.
     * Lectura directa a `execution_deliveries` (los envíos sin entrega abierta no aparecen).
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string>
     */
    private function deliveryVehicles(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $map = [];
        foreach (DB::table('execution_deliveries')->whereIn('shipment_id', $shipmentIds)->get(['shipment_id', 'vehicle_id']) as $row) {
            $map[(string) $row->shipment_id] = (string) $row->vehicle_id;
        }

        return $map;
    }

    /**
     * Deja rastro en el ciclo de vida de la etiqueta cuando el pedido se REASIGNÓ a otro camión (quién,
     * cuándo, de qué camión a cuál). Solo audita si hubo un movimiento REAL: algún envío cuya entrega ya
     * existía con OTRO vehículo pasó al nuevo (el dominio no mueve entregas ya cerradas). Tolerante: la
     * auditoría nunca rompe la asignación.
     *
     * @param list<string>          $shipmentIds
     * @param array<string, string> $vehiclesBefore
     */
    private function auditReassignmentIfMoved(
        AssignOrderVehicleRequest $request,
        string $orderNumber,
        array $shipmentIds,
        array $vehiclesBefore,
        string $toVehicleId,
        string $toPlate,
    ): void {
        try {
            $fromVehicleId = null;
            $after = $this->deliveryVehicles($shipmentIds);
            foreach ($shipmentIds as $shipmentId) {
                $previous = $vehiclesBefore[$shipmentId] ?? '';
                if ($previous !== '' && $previous !== $toVehicleId && ($after[$shipmentId] ?? '') === $toVehicleId) {
                    $fromVehicleId = $previous;
                    break;
                }
            }

            if ($fromVehicleId === null) {
                return;
            }

            $fromPlate = (string) (DB::table('fleet_vehicles')->where('id', $fromVehicleId)->value('plate') ?? $fromVehicleId);

            $user = $request->user();
            $actorId = $user !== null ? (string) $user->getAuthIdentifier() : 'system';
            $actorName = $actorId;
            if ($user instanceof User && is_string($user->name) && $user->name !== '') {
                $actorName = $user->name;
            }
            $meta = ['from_plate' => $fromPlate, 'to_plate' => $toPlate];

            $lifecycle = app(LabelLifecycle::class);
            foreach (DB::table('labels_emitted')->where('order_id', $orderNumber)->pluck('label_id') as $labelId) {
                $lifecycle->record((string) $labelId, 'reassigned', $actorId, $actorName, $orderNumber, $meta);
            }
        } catch (Throwable) {
            // La auditoría del ciclo de vida nunca debe romper la asignación (ya efectuada): se degrada.
        }
    }
}
