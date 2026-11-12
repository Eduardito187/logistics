<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Command\MarkNotificationRead\MarkNotificationRead;
use Logistics\Notifications\Application\Query\CountUnreadNotifications\CountUnreadNotifications;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Presentation\Http\Support\AuthenticatedUser;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Marca una notificación como leída para el usuario autenticado (puerta app). Idempotente: repetirlo
 * responde 200 con el MISMO read_at original. El dueño del estado de lectura es siempre el usuario del
 * token; nunca se acepta un user_id del cliente, así nadie puede marcar a nombre de otro.
 *
 * 401 sin usuario identificable, 404 si la notificación no existe, 422 si el id no es un UUID.
 */
final readonly class MarkNotificationReadController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $notification): JsonResponse
    {
        Validator::make(
            ['notification' => $notification],
            ['notification' => ['required', 'uuid']],
        )->validate();

        $userId = AuthenticatedUser::id($request);
        if ($userId === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        if ($this->queryBus->ask(new GetNotification($notification)) === null) {
            return new JsonResponse(['message' => 'Notification not found.'], 404);
        }

        $this->commandBus->dispatch(new MarkNotificationRead($notification, $userId));

        /** @var NotificationView|null $view */
        $view = $this->queryBus->ask(new GetNotification($notification, $userId));
        /** @var int $unread */
        $unread = $this->queryBus->ask(new CountUnreadNotifications($userId));

        return new JsonResponse([
            'data' => [
                'notification_id' => $notification,
                'read_at' => $view?->readAt,
                'unread_count' => $unread,
            ],
        ]);
    }
}
