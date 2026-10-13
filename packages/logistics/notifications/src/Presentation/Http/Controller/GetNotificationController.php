<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Obtiene una notificación por id (puerta app). Devuelve 404 si no existe.
 */
final readonly class GetNotificationController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(string $notification): JsonResponse
    {
        Validator::make(
            ['notification' => $notification],
            ['notification' => ['required', 'uuid']],
        )->validate();

        /** @var NotificationView|null $view */
        $view = $this->queryBus->ask(new GetNotification($notification));

        if ($view === null) {
            return new JsonResponse(['message' => 'Notification not found.'], 404);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
