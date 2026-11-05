<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Application\Command\CancelOrder\CancelOrder;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Presentation\Http\ApiController;
use Commerce\Orders\Presentation\Http\Request\CancelOrderRequest;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cancela una orden por su id (puertas `channels` y `integrations`, ambas con origen `pos` — ADR 020).
 *
 * Se consulta primero la existencia de la orden (GetOrder) para responder 404 cuando no existe; si
 * existe, se despacha CancelOrder al bus CQRS. La cancelación es una escritura asíncrona (outbox): la
 * respuesta es un 202 de acuse. Las violaciones de regla de dominio las traduce guard() a 422.
 */
final class CancelOrderController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(CancelOrderRequest $request, string $order): JsonResponse
    {
        return $this->guard(function () use ($request, $order): JsonResponse {
            if ($this->queries->ask(new GetOrder(orderId: $order)) === null) {
                return $this->error('order_not_found', 'No order found for the given identifier.', 404);
            }

            $this->commands->dispatch(new CancelOrder(
                orderId: $order,
                reason: $request->reason(),
                source: 'pos',
            ));

            return $this->accepted([
                'order_id' => $order,
            ]);
        });
    }
}
