<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RemoveRoleFromUser\RemoveRoleFromUser;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Quita un rol (por clave) a un usuario (comando RemoveRoleFromUser). El id y la clave llegan por la ruta.
 */
final class RemoveRoleFromUserController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $userId, string $roleKey): JsonResponse
    {
        return $this->guard(function () use ($userId, $roleKey): JsonResponse {
            $this->commands->dispatch(new RemoveRoleFromUser(
                userId: $userId,
                roleKey: $roleKey,
            ));

            return $this->accepted([
                'user_id' => $userId,
                'role_key' => $roleKey,
            ]);
        });
    }
}
