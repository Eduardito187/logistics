<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer\AcknowledgeStockTransfer;
use Logistics\SapConnector\Presentation\Http\Request\AcknowledgeStockTransferRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Puerta INTEGRATIONS: callback de SAP que confirma un traspaso de stock ya empujado. Cierra el ciclo
 * del traspaso (lo saca del panel de reconciliación). Idempotente por `event_id`: reconfirmar no falla.
 */
final class AcknowledgeStockTransferController
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(AcknowledgeStockTransferRequest $request): JsonResponse
    {
        /** @var array{event_id: string} $data */
        $data = $request->validated();

        $this->commandBus->dispatch(new AcknowledgeStockTransfer($data['event_id']));

        return new JsonResponse([
            'data' => [
                'event_id' => $data['event_id'],
                'status' => 'ACKNOWLEDGED',
            ],
            'meta' => [
                'message' => 'Stock transfer acknowledged from SAP.',
            ],
        ]);
    }
}
