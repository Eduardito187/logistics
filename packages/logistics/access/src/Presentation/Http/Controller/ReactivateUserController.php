<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\ReactivateUser\ReactivateUser;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactiva a un usuario suspendido (comando ReactivateUser). El id llega por la ruta.
 */
final class ReactivateUserController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $userId): JsonResponse
    {
        return $this->guard(function () use ($userId): JsonResponse {
            $this->commands->dispatch(new ReactivateUser(userId: $userId));

            return $this->accepted(['user_id' => $userId]);
        });
    }
}
