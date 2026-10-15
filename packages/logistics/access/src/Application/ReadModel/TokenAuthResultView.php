<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use Logistics\Access\Domain\Model\IntegrationToken;

/**
 * Resultado de autenticar un token de integración: su id y los permisos que concede.
 *
 * El resolvedor de permisos del orquestador parte de estas claves para autorizar la petición entrante.
 */
final readonly class TokenAuthResultView
{
    /**
     * @param list<string> $permissionKeys
     */
    private function __construct(
        public string $tokenId,
        public array $permissionKeys,
    ) {}

    public static function fromDomain(IntegrationToken $token): self
    {
        return new self(
            $token->id()->toString(),
            $token->permissionKeys(),
        );
    }

    /**
     * @return array{token_id: string, permission_keys: list<string>}
     */
    public function toArray(): array
    {
        return [
            'token_id' => $this->tokenId,
            'permission_keys' => $this->permissionKeys,
        ];
    }
}
