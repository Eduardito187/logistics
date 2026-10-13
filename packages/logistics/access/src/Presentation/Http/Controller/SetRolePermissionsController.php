<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\SetRolePermissions\SetRolePermissions;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\SetRolePermissionsRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reemplaza el conjunto completo de permisos de un rol (comando SetRolePermissions). El id llega por la ruta.
 */
final class SetRolePermissionsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetRolePermissionsRequest $request, string $roleId): JsonResponse
    {
        return $this->guard(function () use ($request, $roleId): JsonResponse {
            /** @var list<string> $permissionKeys */
            $permissionKeys = array_values($request->input('permission_keys', []));

            $this->commands->dispatch(new SetRolePermissions(
                roleId: $roleId,
                permissionKeys: $permissionKeys,
            ));

            return $this->accepted(['role_id' => $roleId]);
        });
    }
}
