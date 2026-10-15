<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Network\Application\Command\RemoveLeg\RemoveLegCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Elimina un tramo/corredor de la red desde la puerta de integraciones (datos maestros de red).
 *
 * Despacha el comando al CommandBus; responde 202 Accepted.
 */
final class RemoveLegController
{
    public function __invoke(string $leg, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RemoveLegCommand($leg));

        return new JsonResponse(null, 202);
    }
}
