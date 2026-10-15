<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Command\DefineLeg\DefineLegCommand;
use Logistics\Network\Presentation\Http\Request\DefineLegRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define un tramo/corredor entre dos nodos desde la puerta de integraciones (datos maestros de red).
 *
 * Despacha el comando al CommandBus; el alta efectiva y sus efectos (eventos vía outbox) ocurren
 * dentro del handler. Responde 202 Accepted.
 */
final class DefineLegController
{
    public function __invoke(DefineLegRequest $request, CommandBus $bus): JsonResponse
    {
        /** @var array{from_node_id: string, to_node_id: string, corridor_type: string} $data */
        $data = $request->validated();

        $bus->dispatch(new DefineLegCommand(
            $data['from_node_id'],
            $data['to_node_id'],
            $data['corridor_type'],
        ));

        return new JsonResponse([
            'data' => [
                'from_node_id' => $data['from_node_id'],
                'to_node_id' => $data['to_node_id'],
                'corridor_type' => $data['corridor_type'],
            ],
        ], 202);
    }
}
