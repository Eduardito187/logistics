<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use Logistics\Access\Domain\Model\Permission;

/**
 * Proyección de lectura de un permiso para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class PermissionView
{
    private function __construct(
        public string $id,
        public string $key,
        public string $group,
        public string $action,
        public ?string $description,
        public bool $isSystem,
    ) {}

    public static function fromDomain(Permission $permission): self
    {
        return new self(
            $permission->id()->toString(),
            $permission->key()->value,
            $permission->group(),
            $permission->action(),
            $permission->description(),
            $permission->isSystem(),
        );
    }

    /**
     * @return array{id: string, key: string, group: string, action: string, description: string|null, is_system: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'group' => $this->group,
            'action' => $this->action,
            'description' => $this->description,
            'is_system' => $this->isSystem,
        ];
    }
}
