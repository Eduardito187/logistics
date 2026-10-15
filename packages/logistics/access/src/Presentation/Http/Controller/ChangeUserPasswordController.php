<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\ChangeUserPassword\ChangeUserPassword;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\ChangeUserPasswordRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cambia la contraseña de un usuario (comando ChangeUserPassword). El id llega por la ruta.
 */
final class ChangeUserPasswordController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(ChangeUserPasswordRequest $request, string $userId): JsonResponse
    {
        return $this->guard(function () use ($request, $userId): JsonResponse {
            $this->commands->dispatch(new ChangeUserPassword(
                userId: $userId,
                newPlainPassword: $request->string('password')->toString(),
            ));

            return $this->accepted(['user_id' => $userId]);
        });
    }
}
