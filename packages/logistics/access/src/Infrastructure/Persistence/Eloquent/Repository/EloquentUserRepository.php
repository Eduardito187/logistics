<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Access\Domain\Contract\UserRepository;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper\UserMapper;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;

/**
 * Implementación Eloquent del repositorio de usuarios. `save` es un upsert por id.
 */
final readonly class EloquentUserRepository implements UserRepository
{
    public function __construct(
        private UserMapper $mapper = new UserMapper,
    ) {}

    public function nextIdentity(): UserId
    {
        return UserId::generate();
    }

    public function save(User $user): void
    {
        $row = $this->mapper->toRow($user);
        UserRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(UserId $id): ?User
    {
        $record = UserRecord::query()->find($id->toString());

        return $record instanceof UserRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $record = UserRecord::query()->where('email', strtolower(trim($email)))->first();

        return $record instanceof UserRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(UserRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (UserRecord $record): User => $this->mapper->toDomain($record))
            ->all());
    }

    public function existsByEmail(string $email): bool
    {
        return UserRecord::query()->where('email', strtolower(trim($email)))->exists();
    }
}
