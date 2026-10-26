<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista el log global de notificaciones, de la más reciente a la más antigua (puerta app:
 * operación/soporte).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte). Acepta `page`/`per_page` (o `cursor`); traduce a LIMIT/OFFSET.
 *
 * Solo habla con el QueryBus y devuelve el envelope data + meta.
 */
final readonly class ListNotificationsController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var Page<NotificationView> $page */
        $page = $this->queryBus->ask(new ListNotifications(
            limit: ApiPagination::limit($request),
            offset: ApiPagination::offset($request),
        ));

        $items = array_map(
            static fn (NotificationView $view): array => $view->toArray(),
            $page->items,
        );

        return new JsonResponse(ApiPagination::envelope($items, $page->total, $request));
    }
}
