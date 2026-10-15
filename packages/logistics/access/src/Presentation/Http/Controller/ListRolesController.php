<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Access\Application\Query\ListRoles\ListRoles;
use Logistics\Access\Application\ReadModel\RoleView;
use Logistics\Access\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista todos los roles del catálogo de RBAC como proyecciones de lectura (consulta ListRoles).
 */
final class ListRolesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(): JsonResponse
    {
        return $this->guard(function (): JsonResponse {
            /** @var list<RoleView> $roles */
            $roles = $this->queries->ask(new ListRoles);

            return $this->data(
                array_map(static fn (RoleView $view): array => $view->toArray(), $roles),
            );
        });
    }
}
