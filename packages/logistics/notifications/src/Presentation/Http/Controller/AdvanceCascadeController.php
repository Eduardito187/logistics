<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Command\AdvanceCascade\AdvanceCascade;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Avanza la cascada de una notificación: si no hubo apertura en el canal actual, escala al siguiente
 * (puerta integrations). Lo dispara el relay del outbox o el callback de apertura al vencer la ventana.
 *
 * Responde 202 Accepted: el salto de canal queda encolado y se materializa por outbox.
 */
final readonly class AdvanceCascadeController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(string $notification): JsonResponse
    {
        // El id de la notificación llega por la ruta; se valida como UUID antes de despachar.
        Validator::make(
            ['notification' => $notification],
            ['notification' => ['required', 'uuid']],
        )->validate();

        $this->commandBus->dispatch(new AdvanceCascade($notification));

        return new JsonResponse([
            'data' => [
                'notification_id' => $notification,
                'status' => 'accepted',
            ],
        ], 202);
    }
}
