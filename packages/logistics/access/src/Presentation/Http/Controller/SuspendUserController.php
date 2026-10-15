<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\SuspendUser\SuspendUser;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Suspende a un usuario (comando SuspendUser). El id llega por la ruta.
 */
final class SuspendUserController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $userId): JsonResponse
    {
        return $this->guard(function () use ($userId): JsonResponse {
            $this->commands->dispatch(new SuspendUser(userId: $userId));

            return $this->accepted(['user_id' => $userId]);
        });
    }
}
