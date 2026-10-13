<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\Command\DiscardMessage\DiscardMessageCommand;
use Logistics\Outbox\Presentation\Http\Request\DiscardMessageRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Descarta manualmente un mensaje del outbox (lo manda a dead-letter) con un motivo de auditoría.
 *
 * Despacha el comando al CommandBus (transaccional). Responde 202 Accepted.
 */
final class DiscardMessageController
{
    public function __invoke(string $id, DiscardMessageRequest $request, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new DiscardMessageCommand($id, $request->reason()));

        return new JsonResponse(['data' => ['id' => $id, 'status' => 'dead_lettered']], 202);
    }
}
