<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\Query\ListOutboxMessages\ListOutboxMessagesQuery;
use Logistics\Outbox\Application\ReadModel\OutboxMessagePage;
use Logistics\Outbox\Presentation\Http\Request\ListOutboxMessagesRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los mensajes del outbox (paginados, filtrables por estado) para el tablero de operación.
 *
 * Llama al QueryBus (nunca a Infrastructure ni al dominio). Devuelve el envelope data + meta.
 */
final class ListOutboxMessagesController
{
    public function __invoke(ListOutboxMessagesRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var OutboxMessagePage $page */
        $page = $bus->ask(new ListOutboxMessagesQuery(
            $request->statusFilter(),
            $request->page(),
            $request->perPage(),
        ));

        return new JsonResponse($page->toArray());
    }
}
