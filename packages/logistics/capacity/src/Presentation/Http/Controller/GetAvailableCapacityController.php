<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Query\GetAvailableCapacity\GetAvailableCapacityQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Presentation\Http\Request\AvailableCapacityRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el disponible de un cupo por su clave natural (scope/fecha/franja/unidad).
 *
 * Llama al QueryBus (nunca a Infrastructure ni al dominio). Responde 404 con problem+json si no
 * existe un bucket para esa clave.
 */
final class GetAvailableCapacityController
{
    public function __invoke(AvailableCapacityRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var CapacityView|null $view */
        $view = $bus->ask(new GetAvailableCapacityQuery(
            $request->nodeId(),
            $request->zoneId(),
            (string) $request->input('date'),
            (string) $request->input('time_slot'),
            (string) $request->input('unit'),
        ));

        if ($view === null) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Capacity bucket not found',
                'status' => 404,
                'detail' => 'No capacity bucket exists for the requested scope, date, slot and unit.',
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
