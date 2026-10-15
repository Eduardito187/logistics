<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Access\Domain\Model\User;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Domain\ValueObject\UserStatus;
use Logistics\Access\Infrastructure\Persistence\Eloquent\Model\UserRecord;

/**
 * Traduce entre el agregado User (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class UserMapper
{
    public function toDomain(UserRecord $record): User
    {
        $lastLoginAt = $record->last_login_at;

        return User::reconstitute(
            UserId::fromString($record->id),
            $record->name,
            EmailAddress::fromString($record->email),
            $record->password_hash,
            UserStatus::from($record->status),
            array_values($record->role_keys),
            $lastLoginAt instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($lastLoginAt)
                : null,
        );
    }

    /**
     * @return array{id: string, name: string, email: string, password_hash: string, status: string, role_keys: list<string>, last_login_at: DateTimeImmutable|null}
     */
    public function toRow(User $user): array
    {
        return [
            'id' => $user->id()->toString(),
            'name' => $user->name(),
            'email' => $user->email()->value,
            'password_hash' => $user->passwordHash(),
            'status' => $user->status()->value,
            'role_keys' => array_values($user->roleKeys()),
            'last_login_at' => $user->lastLoginAt(),
        ];
    }
}
