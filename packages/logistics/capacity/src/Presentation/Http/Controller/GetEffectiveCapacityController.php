<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityQuery;
use Logistics\Capacity\Application\ReadModel\EffectiveCapacityView;
use Logistics\Capacity\Presentation\Http\Request\AvailableCapacityRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve la capacidad efectiva de una clave (cupo explícito o total por defecto de las reglas).
 *
 * A diferencia de GetAvailableCapacity, nunca responde 404: si no hay ni cupo ni regla, devuelve el
 * modelo con capacidad cero y source 'none'. Llama al QueryBus (nunca a Infrastructure ni al dominio).
 */
final class GetEffectiveCapacityController
{
    public function __invoke(AvailableCapacityRequest $request, QueryBus $bus): JsonResponse
    {
        /** @var EffectiveCapacityView $view */
        $view = $bus->ask(new GetEffectiveCapacityQuery(
            $request->nodeId(),
            $request->zoneId(),
            (string) $request->input('date'),
            (string) $request->input('time_slot'),
            (string) $request->input('unit'),
        ));

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
