<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RevokePermissionFromRole\RevokePermissionFromRole;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\RolePermissionRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Revoca un permiso de un rol (comando RevokePermissionFromRole). El id del rol llega por la ruta.
 */
final class RevokePermissionFromRoleController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RolePermissionRequest $request, string $roleId): JsonResponse
    {
        return $this->guard(function () use ($request, $roleId): JsonResponse {
            $this->commands->dispatch(new RevokePermissionFromRole(
                roleId: $roleId,
                permissionKey: $request->string('permission_key')->toString(),
            ));

            return $this->accepted([
                'role_id' => $roleId,
                'permission_key' => $request->string('permission_key')->toString(),
            ]);
        });
    }
}
