<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\FindNode\FindNodeQuery;
use Logistics\Network\Application\ReadModel\NodeView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un nodo por su UUID (puerta app, solo lectura).
 *
 * Responde 404 con problem+json si el nodo no existe.
 */
final class ShowNodeController
{
    public function __invoke(string $node, QueryBus $bus): JsonResponse
    {
        /** @var NodeView|null $view */
        $view = $bus->ask(new FindNodeQuery($node));

        if ($view === null) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Node not found',
                'status' => 404,
                'detail' => "No node exists with id {$node}.",
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
