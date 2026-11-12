<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Presentation\Http\Request\AssignOrderVehicleRequest;

/**
 * POST /app/orders/{orderNumber}/vehicle — asigna el pedido a un camión de reparto REAL y lo ATA a la
 * ruta de ese vehículo (puerta app). Antes, la selección de camión vivía solo en el cliente; ahora el
 * pedido aparece en «Mi ruta de hoy» del chofer del camión y queda listo para el POD.
 *
 * Valida (cross-context, lectura directa fuera del dominio como el resto del admin): que el pedido exista
 * (404) y que el vehículo exista y esté OPERATIVO (422 si no, nunca 500). Una orden puede tener varios
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

        $assigned = [];
        foreach ($shipments as $shipment) {
            $this->commandBus->dispatch(new AttachShipmentToRoute(
                vehicleId: $vehicleId,
                shipmentId: (string) $shipment->id,
                orderReference: $orderNumber,
                customerName: is_string($shipment->customer_name ?? null) ? $shipment->customer_name : null,
                address: is_string($shipment->address_text ?? null) ? $shipment->address_text : null,
            ));
            $assigned[] = ['shipment_id' => (string) $shipment->id, 'status' => (string) $shipment->status];
        }

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
}
