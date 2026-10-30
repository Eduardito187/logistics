<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Http\Controller;

use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Application\ReadModel\OrderView;
use Commerce\Orders\Presentation\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve una orden por su id (consulta GetOrder). Responde 404 si no existe.
 */
final class GetOrderController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $orderId): JsonResponse
    {
        return $this->guard(function () use ($orderId): JsonResponse {
            /** @var OrderView|null $order */
            $order = $this->queries->ask(new GetOrder(orderId: $orderId));

            if ($order === null) {
                return $this->error('order_not_found', 'No order found for the given identifier.', 404);
            }

            return $this->data($order->toArray());
        });
    }
}
