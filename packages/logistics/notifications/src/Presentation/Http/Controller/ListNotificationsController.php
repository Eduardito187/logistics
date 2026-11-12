<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use App\Http\Support\ApiPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\CountUnreadNotifications\CountUnreadNotifications;
use Logistics\Notifications\Application\Query\ListNotifications\ListNotifications;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Presentation\Http\Support\AuthenticatedUser;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista el log global de notificaciones, de la más reciente a la más antigua (puerta app:
 * operación/soporte).
 *
 * Tabla de crecimiento ilimitado: paginación OBLIGATORIA (per_page por defecto 50), con recorte en SQL
 * (LIMIT/OFFSET + COUNT(*) aparte). Acepta `page`/`per_page` (o `cursor`); traduce a LIMIT/OFFSET.
 *
 * Estado de lectura por usuario (aditivo): cada ítem trae `read_at` del usuario autenticado y
 * `meta.unread_count` el total de no leídas (badge). `?unread=true` filtra solo las no leídas.
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
        Validator::make(
            $request->query->all(),
            ['unread' => ['sometimes', 'nullable', 'in:true,false,1,0']],
        )->validate();

        $userId = AuthenticatedUser::id($request);

        /** @var Page<NotificationView> $page */
        $page = $this->queryBus->ask(new ListNotifications(
            limit: ApiPagination::limit($request),
            offset: ApiPagination::offset($request),
            userId: $userId,
            unreadOnly: $request->boolean('unread'),
        ));

        $items = array_map(
            static fn (NotificationView $view): array => $view->toArray(),
            $page->items,
        );

        $body = ApiPagination::envelope($items, $page->total, $request);

        /** @var int|null $unread */
        $unread = $userId !== null ? $this->queryBus->ask(new CountUnreadNotifications($userId)) : null;
        $body['meta']['unread_count'] = $unread;

        return new JsonResponse($body);
    }
}
