<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Command\RenameRole\RenameRole;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Access\Presentation\Http\Request\RenameRoleRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Renombra un rol (nombre y/o descripción) despachando el comando RenameRole. El id llega por la ruta.
 */
final class RenameRoleController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RenameRoleRequest $request, string $roleId): JsonResponse
    {
        return $this->guard(function () use ($request, $roleId): JsonResponse {
            $description = $request->input('description');

            $this->commands->dispatch(new RenameRole(
                roleId: $roleId,
                name: $request->string('name')->toString(),
                description: is_string($description) && $description !== '' ? $description : null,
            ));

            return $this->accepted(['role_id' => $roleId]);
        });
    }
}
