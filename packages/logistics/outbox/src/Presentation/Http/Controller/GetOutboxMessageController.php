<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\Query\GetOutboxMessage\GetOutboxMessageQuery;
use Logistics\Outbox\Application\ReadModel\OutboxMessageView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el detalle de un mensaje del outbox por su id (estado, reintentos, último error, payload).
 *
 * Responde 404 con problem+json si no existe.
 */
final class GetOutboxMessageController
{
    public function __invoke(string $id, QueryBus $bus): JsonResponse
    {
        /** @var OutboxMessageView|null $view */
        $view = $bus->ask(new GetOutboxMessageQuery($id));

        if ($view === null) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Outbox message not found',
                'status' => 404,
                'detail' => 'No outbox message exists with the requested id.',
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
