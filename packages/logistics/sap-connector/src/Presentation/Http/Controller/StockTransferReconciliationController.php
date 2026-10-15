<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Query\ListFailedTransfers\ListFailedTransfers;
use Logistics\SapConnector\Application\Query\ListPendingTransfers\ListPendingTransfers;
use Logistics\SapConnector\Application\ReadModel\StockTransferView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta APP: reconciliación de traspasos de stock hacia SAP para operaciones. Lee por el QueryBus los
 * traspasos pendientes (Pending + Failed) y los fallidos, y permite disparar manualmente una corrida de
 * despacho (reintento) por el CommandBus. Alimenta el mismo caso de uso que el panel Filament.
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
     * POST: dispara una corrida del worker de despacho (reintento de los pendientes/fallidos).
     */
    public function dispatch(): JsonResponse
    {
        $this->commandBus->dispatch(new DispatchPendingTransfers);

        return new JsonResponse([
            'data' => [
                'status' => 'DISPATCH_REQUESTED',
            ],
            'meta' => [
                'message' => 'Pending stock transfers scheduled for dispatch to SAP.',
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
