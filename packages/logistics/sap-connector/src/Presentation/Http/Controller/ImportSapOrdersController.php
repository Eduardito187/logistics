<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrders;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Logistics\SapConnector\Application\Dto\ImportedOrderDto;

/**
 * Puerta INTEGRATIONS: dispara la lectura de pedidos pendientes en SAP y devuelve los pedidos ya
 * traducidos al modelo propio (capa anti-corrupción, ADR 002).
 *
 * El comando ImportSapOrders no devuelve datos por el CommandBus (dispatch() es void), pero su handler
 * expone los pedidos traducidos vía importedOrders(). Por eso se resuelve el handler directamente —el
 * handler es capa de Application, no Infrastructure— siguiendo el mismo patrón que el worker de consola
 * del contexto (DispatchStockTransfersCommand). El empuje real a SAP nunca ocurre dentro de este
 * request: el escaneo y la reconciliación corren por el worker asíncrono.
 */
final class ImportSapOrdersController
{
    public function __invoke(ImportSapOrdersHandler $handler): JsonResponse
    {
        $handler->handle(new ImportSapOrders);

        $orders = array_map(
            static fn (ImportedOrderDto $order): array => $order->toArray(),
            $handler->importedOrders(),
        );

        return new JsonResponse([
            'data' => $orders,
            'meta' => [
                'count' => count($orders),
            ],
        ]);
    }
}
