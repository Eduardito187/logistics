<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotification;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Suprime una notificación encolada: deja de participar en la cascada (puerta app). Responde 202.
 */
final readonly class SuppressNotificationController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function __invoke(string $notification): JsonResponse
    {
        Validator::make(
            ['notification' => $notification],
            ['notification' => ['required', 'uuid']],
        )->validate();

        $this->commandBus->dispatch(new SuppressNotification($notification));

        return new JsonResponse([
            'data' => [
                'notification_id' => $notification,
                'status' => 'suppressed',
            ],
        ], 202);
    }
}
