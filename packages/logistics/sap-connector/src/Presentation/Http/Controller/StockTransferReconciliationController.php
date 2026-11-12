<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\RequestStockTransferDispatch\RequestStockTransferDispatch;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\ReadModel\StockTransferView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta APP: reconciliación de traspasos de stock hacia SAP para operaciones. Lee por el QueryBus los
 * traspasos pendientes (Pending + Failed) y los fallidos, y permite pedir una corrida de reconciliación
 * por el CommandBus, que solo la encola en la cola `sap` (nunca HTTP a SAP dentro del request).
 */
final class StockTransferReconciliationController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * GET: traspasos pendientes de empuje a SAP (Pending y Failed).
     */
    public function pending(): JsonResponse
    {
        /** @var list<StockTransferView> $transfers */
        $transfers = $this->queryBus->ask(new ListPendingTransfers);

        return $this->collection($transfers);
    }

    /**
     * GET: traspasos cuyo empuje a SAP falló y requieren seguimiento.
     */
    public function failed(): JsonResponse
    {
        /** @var list<StockTransferView> $transfers */
        $transfers = $this->queryBus->ask(new ListFailedTransfers);

        return $this->collection($transfers);
    }

    /**
     * POST: pide una corrida de reconciliación (Failed + Pending estancados). Solo la ENCOLA en la cola
     * `sap` y responde 202 al instante: SAP nunca se llama dentro de este request.
     */
    public function dispatch(): JsonResponse
    {
        $this->commandBus->dispatch(new RequestStockTransferDispatch);

        return new JsonResponse([
            'data' => [
                'status' => 'DISPATCH_REQUESTED',
            ],
            'meta' => [
                'message' => 'Stock transfer reconciliation queued; SAP is contacted in the background.',
            ],
        ], JsonResponse::HTTP_ACCEPTED);
    }

    /**
     * Envuelve una lista de read models en el envelope de respuesta estándar.
     *
     * @param list<StockTransferView> $transfers
     */
    private function collection(array $transfers): JsonResponse
    {
        $data = array_map(
            static fn (StockTransferView $view): array => $view->toArray(),
            $transfers,
        );

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'count' => count($data),
            ],
        ]);
    }
}
