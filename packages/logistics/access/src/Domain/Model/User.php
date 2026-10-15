<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Model;

use DateTimeImmutable;
use Logistics\Access\Domain\Contract\PasswordHasher;
use Logistics\Access\Domain\Event\UserLoggedIn;
use Logistics\Access\Domain\Event\UserPasswordChanged;
use Logistics\Access\Domain\Event\UserReactivated;
use Logistics\Access\Domain\Event\UserRegistered;
use Logistics\Access\Domain\Event\UserRoleAssigned;
use Logistics\Access\Domain\Event\UserRoleRemoved;
use Logistics\Access\Domain\Event\UserSuspended;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\EmailAddress;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Access\Domain\ValueObject\UserId;
use Logistics\Access\Domain\ValueObject\UserStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Usuario de la plataforma: identidad, credenciales, estado y roles asignados (por clave).
 *
 * El agregado nunca conoce el algoritmo de hashing: la contraseña ya llega hasheada (el handler usa el
 * puerto PasswordHasher) y la verificación delega en ese mismo puerto. Los roles se guardan como lista
 * de claves (RoleKey) para no acoplar al agregado Role; la resolución de permisos vive en el orquestador.
 */
final class User extends AggregateRoot
{
    /**
     * @param list<string> $roleKeys
     */
    private function __construct(
        private readonly UserId $id,
        private string $name,
        private EmailAddress $email,
        private string $passwordHash,
        private UserStatus $status,
        private array $roleKeys,
        private ?DateTimeImmutable $lastLoginAt,
    ) {}

    /**
     * Registra un usuario nuevo. Arranca activo salvo que se indique otro estado.
     *
     * @param list<string> $roleKeys
     */
    public static function register(
        UserId $id,
        string $name,
        EmailAddress $email,
        string $passwordHash,
        array $roleKeys = [],
        UserStatus $status = UserStatus::Active,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw AccessException::because('El nombre del usuario no puede estar vacío.');
        }

        $normalizedRoleKeys = self::normalizeRoleKeys($roleKeys);

        $user = new self($id, $name, $email, $passwordHash, $status, $normalizedRoleKeys, null);
        $user->recordEvent(new UserRegistered(
            $id->toString(),
            $name,
            $email->value,
            $normalizedRoleKeys,
            $status->value,
        ));

        return $user;
    }

    /**
     * Reconstituye un usuario desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<string> $roleKeys
     */
    public static function reconstitute(
        UserId $id,
        string $name,
        EmailAddress $email,
        string $passwordHash,
        UserStatus $status,
        array $roleKeys,
        ?DateTimeImmutable $lastLoginAt,
    ): self {
        return new self($id, $name, $email, $passwordHash, $status, array_values($roleKeys), $lastLoginAt);
    }

    /**
     * Cambia la contraseña. El hash ya llega calculado por el puerto; el agregado no lo recalcula.
     */
    public function changePassword(string $newHash): void
    {
        $this->passwordHash = $newHash;
        $this->recordEvent(new UserPasswordChanged($this->id->toString()));
    }

    /**
     * Suspende al usuario. Idempotente: si ya está suspendido, no hace nada ni graba evento.
     */
    public function suspend(): void
    {
        if ($this->status === UserStatus::Suspended) {
            return;
        }
        $this->status = UserStatus::Suspended;
        $this->recordEvent(new UserSuspended($this->id->toString()));
    }

    /**
     * Reactiva al usuario. Idempotente: si ya está activo, no hace nada ni graba evento.
     */
    public function reactivate(): void
    {
        if ($this->status === UserStatus::Active) {
            return;
        }
        $this->status = UserStatus::Active;
        $this->recordEvent(new UserReactivated($this->id->toString()));
    }

    /**
     * Asigna un rol. Idempotente: si ya lo tiene, no hace nada ni graba evento.
     */
    public function assignRole(RoleKey $role): void
    {
        if (in_array($role->value, $this->roleKeys, true)) {
            return;
        }
        $this->roleKeys[] = $role->value;
        $this->roleKeys = array_values($this->roleKeys);
        $this->recordEvent(new UserRoleAssigned($this->id->toString(), $role->value));
    }

    /**
     * Quita un rol. Idempotente: si no lo tiene, no hace nada ni graba evento.
     */
    public function removeRole(RoleKey $role): void
    {
        if (!in_array($role->value, $this->roleKeys, true)) {
            return;
        }
        $this->roleKeys = array_values(array_filter(
            $this->roleKeys,
            static fn (string $key): bool => $key !== $role->value,
        ));
        $this->recordEvent(new UserRoleRemoved($this->id->toString(), $role->value));
    }

    /**
     * Registra el instante del último login.
     */
    public function recordLogin(DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
        $this->recordEvent(new UserLoggedIn($this->id->toString(), $at));
    }

    /**
     * Verifica una contraseña en claro contra el hash guardado. No graba evento: solo delega al puerto.
     */
    public function verifyPassword(string $plain, PasswordHasher $hasher): bool
    {
        return $hasher->verify($plain, $this->passwordHash);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Hash de la contraseña. Se expone solo para que el mapper lo persista; no sale en modelos de lectura.
     */
    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function roleKeys(): array
    {
        return $this->roleKeys;
    }

    public function lastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /**
     * Normaliza las claves de rol: valida cada una con RoleKey y elimina duplicados.
     *
     * @param  list<string> $roleKeys
     * @return list<string>
     */
    private static function normalizeRoleKeys(array $roleKeys): array
    {
        $normalized = array_map(
            static fn (string $key): string => RoleKey::fromString($key)->value,
            $roleKeys,
        );

        return array_values(array_unique($normalized));
    }
}
