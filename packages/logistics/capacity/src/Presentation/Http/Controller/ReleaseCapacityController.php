<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Presentation\Http\Request\ReleaseCapacityRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Libera capacidad de un cupo (puerta de integraciones: reprogramación o envío fallido).
 *
 * Despacha el comando al CommandBus; la devolución de cupo y sus efectos (eventos vía outbox) ocurren
 * en el handler. Responde 202 Accepted: la respuesta no espera proyecciones externas.
 */
final class ReleaseCapacityController
{
    public function __invoke(ReleaseCapacityRequest $request, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new ReleaseCapacityCommand(
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
