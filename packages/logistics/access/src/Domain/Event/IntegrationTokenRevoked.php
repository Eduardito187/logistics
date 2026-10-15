<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se revocó un token de integración: deja de autenticar de forma permanente.
 */
final readonly class IntegrationTokenRevoked extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'access.integration_token_revoked';
    }

    /**
     * @return array<string, never>
     */
    public function payload(): array
    {
        return [];
    }
}
