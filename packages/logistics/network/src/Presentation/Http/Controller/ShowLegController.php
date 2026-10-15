<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Query\FindLeg\FindLegQuery;
use Logistics\Network\Application\ReadModel\LegView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un tramo por su UUID (puerta app, solo lectura).
 *
 * Responde 404 con problem+json si el tramo no existe.
 */
final class ShowLegController
{
    public function __invoke(string $leg, QueryBus $bus): JsonResponse
    {
        /** @var LegView|null $view */
        $view = $bus->ask(new FindLegQuery($leg));

        if ($view === null) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Leg not found',
                'status' => 404,
                'detail' => "No leg exists with id {$leg}.",
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
