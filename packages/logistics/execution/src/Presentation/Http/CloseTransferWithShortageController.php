<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Command\CloseTransferWithShortage\CloseTransferWithShortage;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * POST /app/deliveries/{shipmentId}/close-transfer — CIERRE MANUAL de un traspaso con faltantes (puerta app).
 *
 * El chofer dejó el traspaso en su tienda destino declarando productos faltantes y la tienda nunca concilió
 * la recepción: quedó en curso. La oficina (solo admin y supervisor: lo exige la ruta) lo da por entregado
 * con un motivo obligatorio. La entrega queda `DELIVERED` con `with_shortage = true` y el hecho de entrega
 * lleva los faltantes; se siguen por el reporte de discrepancias.
 *
 * Solo aplica a un traspaso interno (`delivery_mode = transfer`): otra modalidad responde 422. También
 * responde 422, con el motivo en `detail`, si todavía no hay entrega en tienda registrada o si no se
 * declararon faltantes. Idempotente: repetirlo sobre un traspaso ya entregado devuelve su estado, sin cambios.
 */
final class CloseTransferWithShortageController extends ExecutionController
{
    private const TRANSFER = 'transfer';

    public function __invoke(Request $request, string $shipmentId): JsonResponse
    {
        /** @var array{reason: string} $data */
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        if (!$shipment instanceof ShipmentView) {
            return $this->problem('shipment_not_found', 'Shipment not found.', 404);
        }

        if (strtolower((string) $shipment->deliveryMode) !== self::TRANSFER) {
            return $this->problem(
                'not_a_transfer',
                'Only an internal transfer can be closed with a shortage.',
                422,
            );
        }

        $this->commandBus->dispatch(new CloseTransferWithShortage(
            $shipmentId,
            trim($data['reason']),
            $this->userId($request),
            now()->toAtomString(),
        ));

        return $this->deliveryStateResponse($shipmentId);
    }
}
