<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListRoles;

use Logistics\Access\Application\ReadModel\RoleView;
use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\Model\Role;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de roles como proyecciones de lectura.
 *
 * @implements QueryHandler<ListRoles, list<RoleView>>
 */
final readonly class ListRolesHandler implements QueryHandler
{
    public function __construct(
        private RoleRepository $roles,
    ) {}

    /**
     * @return list<RoleView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListRoles);

        return array_map(
            static fn (Role $role): RoleView => RoleView::fromDomain($role),
            $this->roles->all(),
        );
    }
}
