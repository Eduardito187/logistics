<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Command\SuppressNotification\SuppressNotification;
use Logistics\Notifications\Application\Query\GetNotification\GetNotification;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Presentation\Http\Support\ScopeActorResolver;

/**
 * Suprime una notificación encolada: deja de participar en la cascada (puerta app). Responde 202.
 *
 * 404 si la notificación no existe o es de un envío fuera del alcance del usuario (no se revela).
 */
final readonly class SuppressNotificationController
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

        $scope = ScopeActorResolver::fromUser($request->user());

        if ($this->queryBus->ask(new GetNotification($notification, scope: $scope)) === null) {
            return ProblemDetails::fromCode(ErrorCode::NotFound, 'The notification does not exist.')->toResponse($request);
        }

        $this->commandBus->dispatch(new SuppressNotification($notification, $scope));

        return new JsonResponse([
            'data' => [
                'notification_id' => $notification,
                'status' => 'suppressed',
            ],
        ], 202);
    }
}
