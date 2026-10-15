<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista el log global de notificaciones, de la más reciente a la más antigua (puerta app:
 * operación/soporte). Acepta `limit` como query param (tope sano por defecto).
 *
 * Solo habla con el QueryBus y devuelve NotificationView en forma primitiva.
 */
final readonly class ListNotificationsController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', '100');
        $limit = max(1, min($limit, 500));

        /** @var list<NotificationView> $views */
        $views = $this->queryBus->ask(new ListNotifications($limit));

        return new JsonResponse([
            'data' => array_map(
                static fn (NotificationView $view): array => $view->toArray(),
                $views,
            ),
        ]);
    }
}
