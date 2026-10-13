<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use Logistics\Access\Domain\Model\User;

/**
 * Proyección de lectura de un usuario recién autenticado. Lleva solo lo que necesita quien emite la
 * sesión; los permisos efectivos los agrega el PermissionResolver del orquestador a partir de los roles.
 */
final readonly class AuthenticatedUserView
{
    /**
     * @param list<string> $roleKeys
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $email,
        public array $roleKeys,
    ) {}

    public static function fromDomain(User $user): self
    {
        return new self(
            $user->id()->toString(),
            $user->name(),
            $user->email()->value,
            $user->roleKeys(),
        );
    }

    /**
     * @return array{id: string, name: string, email: string, role_keys: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role_keys' => $this->roleKeys,
        ];
    }
}
