<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\Model\Role;
use Logistics\Access\Domain\ValueObject\RoleId;

/**
 * Puerto de persistencia de roles. La implementación vive en Infrastructure.
 */
interface RoleRepository
{
    public function nextIdentity(): RoleId;

    public function save(Role $role): void;

    public function findById(RoleId $id): ?Role;

    public function findByKey(string $key): ?Role;

    /**
     * @return list<Role>
     */
    public function all(): array;

    public function delete(RoleId $id): void;
}
