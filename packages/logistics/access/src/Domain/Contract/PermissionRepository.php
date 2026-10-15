<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\Model\Permission;
use Logistics\Access\Domain\ValueObject\PermissionId;

/**
 * Puerto de persistencia del catálogo de permisos. La implementación vive en Infrastructure.
 */
interface PermissionRepository
{
    public function nextIdentity(): PermissionId;

    public function save(Permission $permission): void;

    public function findByKey(string $key): ?Permission;

    public function findById(PermissionId $id): ?Permission;

    /**
     * @return list<Permission>
     */
    public function all(): array;

    public function existsByKey(string $key): bool;
}
