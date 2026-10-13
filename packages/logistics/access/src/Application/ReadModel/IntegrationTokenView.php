<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use DateTimeInterface;
use Logistics\Access\Domain\Model\IntegrationToken;

/**
 * Proyección de lectura de un token de integración para listados y API.
 *
 * Primitivos: no expone objetos de dominio ni el secreto en claro (solo el prefijo público y el estado).
 */
final readonly class IntegrationTokenView
{
    /**
     * @param list<string> $permissionKeys
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $tokenPrefix,
        public string $status,
        public array $permissionKeys,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public ?string $createdBy,
    ) {}

    public static function fromDomain(IntegrationToken $token): self
    {
        return new self(
            $token->id()->toString(),
            $token->name(),
            $token->tokenPrefix(),
            $token->status()->value,
            $token->permissionKeys(),
            $token->expiresAt()?->format(DateTimeInterface::ATOM),
            $token->lastUsedAt()?->format(DateTimeInterface::ATOM),
            $token->createdBy(),
        );
    }

    /**
     * @return array{id: string, name: string, token_prefix: string, status: string, permission_keys: list<string>, expires_at: string|null, last_used_at: string|null, created_by: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'token_prefix' => $this->tokenPrefix,
            'status' => $this->status,
            'permission_keys' => $this->permissionKeys,
            'expires_at' => $this->expiresAt,
            'last_used_at' => $this->lastUsedAt,
            'created_by' => $this->createdBy,
        ];
    }
}
