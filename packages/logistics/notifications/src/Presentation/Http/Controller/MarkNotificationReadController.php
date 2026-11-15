<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
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
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Marca una notificación como leída para el usuario autenticado (puerta app). Idempotente: repetirlo
 * responde 200 con el MISMO read_at original. El dueño del estado de lectura es siempre el usuario del
 * token; nunca se acepta un user_id del cliente, así nadie puede marcar a nombre de otro.
 *
 * 401 sin usuario identificable, 404 si la notificación no existe o es de un envío fuera del alcance del
 * usuario (no se revela), 422 si el id no es un UUID.
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

        $scope = ScopeActorResolver::fromUser($request->user());

        if ($this->queryBus->ask(new GetNotification($notification, scope: $scope)) === null) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'The notification does not exist.')->toResponse($request);
        }

        $this->commandBus->dispatch(new MarkNotificationRead($notification, $userId, $scope));

        /** @var NotificationView|null $view */
        $view = $this->queryBus->ask(new GetNotification($notification, $userId, $scope));
        /** @var int $unread */
        $unread = $this->queryBus->ask(new CountUnreadNotifications($userId, $scope));

        return new JsonResponse([
            'data' => [
                'notification_id' => $notification,
                'read_at' => $view?->readAt,
                'unread_count' => $unread,
            ],
        ]);
    }
}
