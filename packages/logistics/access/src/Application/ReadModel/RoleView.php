<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use Logistics\Access\Domain\Model\Role;

/**
 * Proyección de lectura de un rol para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class RoleView
{
    /**
     * @param list<string> $permissionKeys
     */
    private function __construct(
        public string $id,
        public string $key,
        public string $name,
        public ?string $description,
        public bool $isSystem,
        public array $permissionKeys,
    ) {}

    public static function fromDomain(Role $role): self
    {
        return new self(
            $role->id()->toString(),
            $role->key()->value,
            $role->name(),
            $role->description(),
            $role->isSystem(),
            $role->permissionKeys(),
        );
    }

    /**
     * @return array{id: string, key: string, name: string, description: string|null, is_system: bool, permission_keys: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->isSystem,
            'permission_keys' => $this->permissionKeys,
        ];
    }
}
