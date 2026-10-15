<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Query\ListPermissions\ListPermissions;
use Logistics\Access\Application\ReadModel\PermissionView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista los permisos del catálogo de RBAC, con filtro opcional por grupo (consulta ListPermissions).
 */
final class ListPermissionsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $group = $request->query('group');
            $group = is_string($group) && $group !== '' ? $group : null;

            /** @var list<PermissionView> $permissions */
            $permissions = $this->queries->ask(new ListPermissions(group: $group));

            return $this->data(
                array_map(static fn (PermissionView $view): array => $view->toArray(), $permissions),
            );
        });
    }
}
