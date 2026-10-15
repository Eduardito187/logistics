<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se emitió un token de integración nuevo. El secreto en claro NO viaja en el evento: solo su prefijo
 * público, el nombre, los permisos concedidos y la fecha de expiración.
 */
final readonly class IntegrationTokenIssued extends BaseDomainEvent
{
    /**
     * @param list<string> $permissionKeys
     */
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $tokenPrefix,
        public array $permissionKeys,
        public ?DateTimeImmutable $expiresAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.integration_token_issued';
    }

    /**
     * @return array{name: string, token_prefix: string, permission_keys: list<string>, expires_at: string|null}
     */
    public function payload(): array
    {
        return [
            'name' => $this->name,
            'token_prefix' => $this->tokenPrefix,
            'permission_keys' => $this->permissionKeys,
            'expires_at' => $this->expiresAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
