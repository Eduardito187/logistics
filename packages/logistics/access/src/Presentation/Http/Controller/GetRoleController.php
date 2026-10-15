<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Query\GetRole\GetRole;
use Logistics\Access\Application\ReadModel\RoleView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve un rol por su identidad (consulta GetRole). Responde 404 si no existe.
 */
final class GetRoleController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(string $roleId): JsonResponse
    {
        return $this->guard(function () use ($roleId): JsonResponse {
            /** @var RoleView|null $role */
            $role = $this->queries->ask(new GetRole(roleId: $roleId));

            if ($role === null) {
                return $this->error('role_not_found', 'No role found for the given identifier.', 404);
            }

            return $this->data($role->toArray());
        });
    }
}
