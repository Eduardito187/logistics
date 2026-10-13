<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Model;

use Logistics\Access\Domain\Event\RoleDefined;
use Logistics\Access\Domain\Event\RoleDeleted;
use Logistics\Access\Domain\Event\RolePermissionsChanged;
use Logistics\Access\Domain\Event\RoleRenamed;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Access\Domain\ValueObject\RoleId;
use Logistics\Access\Domain\ValueObject\RoleKey;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Rol del RBAC dinámico: un conjunto nombrado de permisos que luego se asigna a usuarios por su clave.
 *
 * Los permisos se guardan como claves de texto (`list<string>`), no como objetos: es el contrato que
 * comparten roles, usuarios y el resolvedor de permisos. El rol siempre mantiene el conjunto normalizado
 * (claves válidas y sin duplicados). Los roles de sistema (`isSystem`) no se pueden borrar.
 */
final class Role extends AggregateRoot
{
    /**
     * @param list<string> $permissionKeys
     */
    private function __construct(
        private readonly RoleId $id,
        private readonly RoleKey $key,
        private string $name,
        private ?string $description,
        private readonly bool $isSystem,
        private array $permissionKeys,
    ) {}

    /**
     * Define un rol nuevo. Valida el nombre y normaliza las claves de permiso (válidas y sin duplicados).
     *
     * @param list<string> $permissionKeys
     */
    public static function define(
        RoleId $id,
        RoleKey $key,
        string $name,
        ?string $description,
        array $permissionKeys,
        bool $isSystem = false,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw AccessException::because('El nombre del rol no puede estar vacío.');
        }

        $normalized = self::normalizeKeys($permissionKeys);

        $role = new self($id, $key, $name, $description, $isSystem, $normalized);
        $role->recordEvent(new RoleDefined(
            $id->toString(),
            $key->value,
            $name,
            $normalized,
        ));

        return $role;
    }

    /**
     * Reconstituye un rol desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<string> $permissionKeys
     */
    public static function reconstitute(
        RoleId $id,
        RoleKey $key,
        string $name,
        ?string $description,
        bool $isSystem,
        array $permissionKeys,
    ): self {
        return new self($id, $key, $name, $description, $isSystem, array_values($permissionKeys));
    }

    /**
     * Renombra el rol (nombre y descripción). Idempotente: si no cambia nada, no graba evento.
     */
    public function rename(string $name, ?string $description): void
    {
        $name = trim($name);
        if ($name === '') {
            throw AccessException::because('El nombre del rol no puede estar vacío.');
        }
        if ($this->name === $name && $this->description === $description) {
            return;
        }

        $this->name = $name;
        $this->description = $description;
        $this->recordEvent(new RoleRenamed($this->id->toString(), $name));
    }

    /**
     * Otorga un permiso al rol. Idempotente: si ya lo tiene, no hace nada ni graba evento.
     */
    public function grant(PermissionKey $permission): void
    {
        if (in_array($permission->value, $this->permissionKeys, true)) {
            return;
        }

        $this->permissionKeys[] = $permission->value;
        $this->recordEvent(new RolePermissionsChanged($this->id->toString(), $this->permissionKeys));
    }

    /**
     * Revoca un permiso del rol. Idempotente: si no lo tiene, no hace nada ni graba evento.
     */
    public function revoke(PermissionKey $permission): void
    {
        if (!in_array($permission->value, $this->permissionKeys, true)) {
            return;
        }

        $this->permissionKeys = array_values(array_filter(
            $this->permissionKeys,
            static fn (string $key): bool => $key !== $permission->value,
        ));
        $this->recordEvent(new RolePermissionsChanged($this->id->toString(), $this->permissionKeys));
    }

    /**
     * Reemplaza el conjunto completo de permisos (usado por el admin). Idempotente si el set no cambia.
     *
     * @param list<string> $keys
     */
    public function setPermissions(array $keys): void
    {
        $normalized = self::normalizeKeys($keys);
        if ($normalized === $this->permissionKeys) {
            return;
        }

        $this->permissionKeys = $normalized;
        $this->recordEvent(new RolePermissionsChanged($this->id->toString(), $this->permissionKeys));
    }

    /**
     * Indica si el rol concede un permiso. El comodín "*" concede todo.
     */
    public function hasPermission(PermissionKey $permission): bool
    {
        if (in_array(PermissionKey::WILDCARD, $this->permissionKeys, true)) {
            return true;
        }

        return in_array($permission->value, $this->permissionKeys, true);
    }

    /**
     * Garantiza que el rol se pueda borrar. Los roles de sistema no se eliminan.
     */
    public function ensureDeletable(): void
    {
        if ($this->isSystem) {
            throw AccessException::because("El rol de sistema {$this->key->value} no se puede eliminar.");
        }
    }

    /**
     * Marca el borrado del rol como hecho de dominio (para que el handler publique el evento al outbox).
     */
    public function markDeleted(): void
    {
        $this->recordEvent(new RoleDeleted($this->id->toString(), $this->key->value));
    }

    public function id(): RoleId
    {
        return $this->id;
    }

    public function key(): RoleKey
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return $this->permissionKeys;
    }

    /**
     * Normaliza una lista de claves de permiso: valida con PermissionKey y elimina duplicados.
     *
     * @param  list<string> $keys
     * @return list<string>
     */
    private static function normalizeKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            $value = PermissionKey::fromString($key)->value;
            if (!in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }
}
