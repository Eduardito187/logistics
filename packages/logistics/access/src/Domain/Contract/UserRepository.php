<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\UserId;

/**
 * Puerto de persistencia de usuarios. La implementación vive en Infrastructure.
 */
interface UserRepository
{
    public function nextIdentity(): UserId;

    public function save(User $user): void;

    public function findById(UserId $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * @return list<User>
     */
    public function all(): array;

    public function existsByEmail(string $email): bool;
}
