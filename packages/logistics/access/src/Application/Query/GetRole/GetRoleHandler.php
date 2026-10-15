<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\GetRole;

use Logistics\Access\Application\ReadModel\RoleView;
use Logistics\Access\Domain\Contract\RoleRepository;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un rol como proyección de lectura, o null si no existe.
 *
 * @implements QueryHandler<GetRole, RoleView|null>
 */
final readonly class GetRoleHandler implements QueryHandler
{
    public function __construct(
        private RoleRepository $roles,
    ) {}

    public function handle(Query $query): ?RoleView
    {
        assert($query instanceof GetRole);

        $role = $this->roles->findById(RoleId::fromString($query->roleId));

        return $role === null ? null : RoleView::fromDomain($role);
    }
}
