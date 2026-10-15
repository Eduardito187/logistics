<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Model;

use Logistics\Access\Domain\Event\PermissionRegistered;
use Logistics\Access\Domain\ValueObject\PermissionId;
use Logistics\Access\Domain\ValueObject\PermissionKey;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Permiso del catálogo de RBAC dinámico: una capacidad nombrada por su clave estable "<grupo>.<accion>".
 *
 * El grupo y la acción se derivan de la clave (no se pasan por fuera): el grupo es el primer segmento y
 * la acción el último. Los permisos de sistema (`isSystem`) son parte del catálogo base y no se tocan
 * desde el admin. El agregado no conoce qué roles lo usan; solo describe la capacidad.
 */
final class Permission extends AggregateRoot
{
    private function __construct(
        private readonly PermissionId $id,
        private readonly PermissionKey $key,
        private readonly string $group,
        private readonly string $action,
        private readonly ?string $description,
        private readonly bool $isSystem,
    ) {}

    /**
     * Registra un permiso nuevo. Deriva el grupo y la acción de la clave.
     */
    public static function register(
        PermissionId $id,
        PermissionKey $key,
        ?string $description = null,
        bool $isSystem = false,
    ): self {
        $group = $key->group();
        // La acción es el último segmento de la clave ("access.roles.manage" → "manage").
        $segments = explode('.', $key->value);
        $action = $segments[count($segments) - 1];

        $permission = new self($id, $key, $group, $action, $description, $isSystem);
        $permission->recordEvent(new PermissionRegistered(
            $id->toString(),
            $key->value,
            $group,
            $action,
            $isSystem,
        ));

        return $permission;
    }

    /**
     * Reconstituye un permiso desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        PermissionId $id,
        PermissionKey $key,
        string $group,
        string $action,
        ?string $description,
        bool $isSystem,
    ): self {
        return new self($id, $key, $group, $action, $description, $isSystem);
    }

    public function id(): PermissionId
    {
        return $this->id;
    }

    public function key(): PermissionKey
    {
        return $this->key;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }
}
