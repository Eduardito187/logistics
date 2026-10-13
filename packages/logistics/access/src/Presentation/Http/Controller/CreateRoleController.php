<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\DefineRole\DefineRole;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\DefineRoleRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Define un rol nuevo con su conjunto inicial de permisos despachando el comando DefineRole al bus CQRS.
 */
final class CreateRoleController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DefineRoleRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<string> $permissionKeys */
            $permissionKeys = array_values($request->input('permission_keys', []));
            $description = $request->input('description');

            $this->commands->dispatch(new DefineRole(
                key: $request->string('key')->toString(),
                name: $request->string('name')->toString(),
                description: is_string($description) && $description !== '' ? $description : null,
                permissionKeys: $permissionKeys,
                isSystem: $request->boolean('is_system'),
            ));

            return $this->accepted(['key' => $request->string('key')->toString()]);
        });
    }
}
