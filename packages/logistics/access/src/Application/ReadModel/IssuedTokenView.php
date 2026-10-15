<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

/**
 * Resultado de emitir o rotar un token de integración. Incluye el token en claro ("<prefix>.<secret>")
 * que solo se revela en este preciso momento y nunca se puede recuperar después (solo se guarda el hash).
 *
 * El llamador debe entregárselo al integrador de inmediato; la plataforma no lo vuelve a mostrar.
 */
final readonly class IssuedTokenView
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $tokenPrefix,
        public string $plainToken,
        public array $permissionKeys,
        public ?string $expiresAt,
    ) {}

    /**
     * @return array{id: string, name: string, token_prefix: string, plain_token: string, permission_keys: list<string>, expires_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'token_prefix' => $this->tokenPrefix,
            'plain_token' => $this->plainToken,
            'permission_keys' => $this->permissionKeys,
            'expires_at' => $this->expiresAt,
        ];
    }
}
