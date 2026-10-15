<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\Command\RetryMessage\RetryMessageCommand;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reencola un mensaje del outbox (fallido o en dead-letter) para que el relay lo retome.
 *
 * Despacha el comando al CommandBus (transaccional). Responde 202 Accepted: el despacho real lo hace
 * el relay después, por el canal del evento.
 */
final class RetryMessageController
{
    public function __invoke(string $id, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RetryMessageCommand($id));

        return new JsonResponse(['data' => ['id' => $id, 'status' => 'pending']], 202);
    }
}
