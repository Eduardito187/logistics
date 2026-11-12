<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Command\MarkAllNotificationsRead\MarkAllNotificationsRead;
use Logistics\Notifications\Application\Query\CountUnreadNotifications\CountUnreadNotifications;
use Logistics\Notifications\Presentation\Http\Support\AuthenticatedUser;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Marca como leídas todas las notificaciones del usuario autenticado (puerta app), opcionalmente solo
 * las creadas hasta `up_to` (ISO 8601, inclusive; por defecto "ahora"). La app debería mandar el
 * created_at más nuevo que tenía en pantalla, para no dar por leída una que llegó después.
 *
 * Idempotente y monótono: repetirlo, o mandar un up_to anterior al vigente, no cambia nada.
 * Responde 200 con el nuevo unread_count; 401 sin usuario identificable, 422 si up_to es inválido.
 */
final readonly class MarkAllNotificationsReadController
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = Validator::make(
            $request->all(),
            ['up_to' => ['sometimes', 'nullable', 'string', 'date']],
        )->validate();

        $userId = AuthenticatedUser::id($request);
        if ($userId === null) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $upTo = $validated['up_to'] ?? null;

        $this->commandBus->dispatch(new MarkAllNotificationsRead($userId, is_string($upTo) ? $upTo : null));

        /** @var int $unread */
        $unread = $this->queryBus->ask(new CountUnreadNotifications($userId));

        return new JsonResponse([
            'data' => [
                'unread_count' => $unread,
            ],
        ]);
    }
}
