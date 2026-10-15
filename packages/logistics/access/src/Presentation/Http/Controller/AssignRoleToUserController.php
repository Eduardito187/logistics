<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\AssignRoleToUser\AssignRoleToUser;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\AssignRoleToUserRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Asigna un rol (por clave) a un usuario (comando AssignRoleToUser). El id del usuario llega por la ruta.
 */
final class AssignRoleToUserController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(AssignRoleToUserRequest $request, string $userId): JsonResponse
    {
        return $this->guard(function () use ($request, $userId): JsonResponse {
            $this->commands->dispatch(new AssignRoleToUser(
                userId: $userId,
                roleKey: $request->string('role_key')->toString(),
            ));

            return $this->accepted([
                'user_id' => $userId,
                'role_key' => $request->string('role_key')->toString(),
            ]);
        });
    }
}
