<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Presentation\Http\Support\AuthenticatedUser;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Obtiene una notificación por id (puerta app). Devuelve 404 si no existe. Incluye `read_at` del
 * usuario autenticado (null = no leída).
 */
final readonly class GetNotificationController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request, string $notification): JsonResponse
    {
        Validator::make(
            ['notification' => $notification],
            ['notification' => ['required', 'uuid']],
        )->validate();

        /** @var NotificationView|null $view */
        $view = $this->queryBus->ask(new GetNotification($notification, AuthenticatedUser::id($request)));

        if ($view === null) {
            return new JsonResponse(['message' => 'Notification not found.'], 404);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
