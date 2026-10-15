<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Command\DeactivateNode\DeactivateNodeCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Da de baja un nodo desde la puerta de integraciones (sincronización de datos maestros SAP).
 *
 * Despacha el comando al CommandBus; responde 202 Accepted.
 */
final class DeactivateNodeController
{
    public function __invoke(string $node, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new DeactivateNodeCommand($node));

        return new JsonResponse(null, 202);
    }
}
