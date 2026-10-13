<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\EnqueueStockTransfer\EnqueueStockTransfer;
use Logistics\SapConnector\Presentation\Http\Request\EnqueueStockTransferRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta APP: encola un traspaso de stock hacia SAP a partir de una recepción en nodo. Se despacha por
 * el CommandBus y responde 202 Accepted de inmediato: el escaneo NUNCA espera a SAP (ADR 002). El
 * empuje real lo hace el worker de despacho; el resultado se consulta en el panel de reconciliación.
 *
 * Idempotente por `event_id`: reenviar la misma recepción no duplica el traspaso.
 */
final class EnqueueStockTransferController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(EnqueueStockTransferRequest $request): JsonResponse
    {
        /** @var array{event_id: string, shipment_ref: string, sku: string, quantity: int} $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new EnqueueStockTransfer(
            $data['event_id'],
            $data['shipment_ref'],
            $data['sku'],
            $data['quantity'],
        ));

        return new JsonResponse([
            'data' => [
                'event_id' => $data['event_id'],
                'shipment_ref' => $data['shipment_ref'],
                'status' => 'ENQUEUED',
            ],
            'meta' => [
                'message' => 'Stock transfer enqueued for asynchronous dispatch to SAP.',
            ],
        ], JsonResponse::HTTP_ACCEPTED);
    }
}
