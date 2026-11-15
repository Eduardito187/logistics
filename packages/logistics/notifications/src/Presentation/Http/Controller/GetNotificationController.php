<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Notifications\Application\ReadModel\NotificationView;
use Logistics\Notifications\Presentation\Http\Support\AuthenticatedUser;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Obtiene una notificación por id (puerta app). Devuelve 404 si no existe. Incluye `read_at` del
 * usuario autenticado (null = no leída).
 *
 * Alcance por rol: si la notificación es de un envío que el usuario no puede ver, responde el mismo 404
 * que si no existiera (no se revela).
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
        $view = $this->queryBus->ask(new GetNotification(
            $notification,
            AuthenticatedUser::id($request),
            ScopeActorResolver::fromUser($request->user()),
        ));

        if ($view === null) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'The notification does not exist.')->toResponse($request);
        }

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
