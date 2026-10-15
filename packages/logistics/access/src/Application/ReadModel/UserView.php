<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use DateTimeInterface;
use Logistics\Access\Domain\Model\User;

/**
 * Proyección de lectura de un usuario para listados y API. Primitivos: no expone objetos de dominio
 * ni el hash de la contraseña.
 */
final readonly class UserView
{
    /**
     * @param list<string> $roleKeys
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $status,
        public array $roleKeys,
        public ?string $lastLoginAt,
    ) {}

    public static function fromDomain(User $user): self
    {
        $lastLoginAt = $user->lastLoginAt();

        return new self(
            $user->id()->toString(),
            $user->name(),
            $user->email()->value,
            $user->status()->value,
            $user->roleKeys(),
            $lastLoginAt?->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array{id: string, name: string, email: string, status: string, role_keys: list<string>, last_login_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'role_keys' => $this->roleKeys,
            'last_login_at' => $this->lastLoginAt,
        ];
    }
}
