<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\DeleteRole\DeleteRole;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Elimina un rol del catálogo de RBAC (comando DeleteRole). El id llega por la ruta.
 */
final class DeleteRoleController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(string $roleId): JsonResponse
    {
        return $this->guard(function () use ($roleId): JsonResponse {
            $this->commands->dispatch(new DeleteRole(roleId: $roleId));

            return $this->accepted(['role_id' => $roleId]);
        });
    }
}
