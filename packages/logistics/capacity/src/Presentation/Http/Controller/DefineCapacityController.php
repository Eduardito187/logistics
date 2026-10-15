<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Presentation\Http\Request\DefineCapacityRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define un cupo nuevo (puerta de integraciones / aprovisionamiento).
 *
 * Despacha el comando al CommandBus; el alta y sus efectos (eventos vía outbox) ocurren en el
 * handler. Responde 202 Accepted: la respuesta no espera proyecciones externas.
 */
final class DefineCapacityController
{
    public function __invoke(DefineCapacityRequest $request, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new DefineCapacityCommand(
            $request->nodeId(),
            $request->zoneId(),
            (string) $request->input('date'),
            (string) $request->input('time_slot'),
            (string) $request->input('unit'),
            $request->total(),
            $request->municipalityId(),
        ));

        return new JsonResponse([
            'data' => [
                'node_id' => $request->nodeId(),
                'zone_id' => $request->zoneId(),
                'municipality_id' => $request->municipalityId(),
                'date' => (string) $request->input('date'),
                'time_slot' => (string) $request->input('time_slot'),
                'unit' => (string) $request->input('unit'),
            ],
        ], 202);
    }
}
