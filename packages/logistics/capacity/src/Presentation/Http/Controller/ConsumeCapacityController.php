<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Presentation\Http\Request\ConsumeCapacityRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Consume capacidad de un cupo (puerta de integraciones: planning/aprovisionamiento).
 *
 * Despacha el comando al CommandBus; la imputación y sus efectos (eventos vía outbox) ocurren en el
 * handler. Responde 202 Accepted: la respuesta no espera proyecciones externas.
 */
final class ConsumeCapacityController
{
    public function __invoke(ConsumeCapacityRequest $request, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new ConsumeCapacityCommand(
            $request->nodeId(),
            $request->zoneId(),
            (string) $request->input('date'),
            (string) $request->input('time_slot'),
            (string) $request->input('unit'),
            $request->amount(),
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
                'amount' => $request->amount(),
            ],
        ], 202);
    }
}
