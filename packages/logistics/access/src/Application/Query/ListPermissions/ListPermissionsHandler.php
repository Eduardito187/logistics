<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListPermissions;

use Logistics\Access\Application\ReadModel\PermissionView;
use Logistics\Access\Domain\Contract\PermissionRepository;
use Logistics\Access\Domain\Model\Permission;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de permisos como proyecciones de lectura.
 *
 * @implements QueryHandler<ListPermissions, list<PermissionView>>
 */
final readonly class ListPermissionsHandler implements QueryHandler
{
    public function __construct(
        private PermissionRepository $permissions,
    ) {}

    /**
     * @return list<PermissionView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPermissions);

        $permissions = $this->permissions->all();
        if ($query->group !== null) {
            $permissions = array_values(array_filter(
                $permissions,
                static fn (Permission $permission): bool => $permission->group() === $query->group,
            ));
        }

        return array_map(
            static fn (Permission $permission): PermissionView => PermissionView::fromDomain($permission),
            $permissions,
        );
    }
}
