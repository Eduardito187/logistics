<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se rotó el secreto de un token de integración: se emitió un secreto nuevo con un prefijo nuevo y el
 * anterior deja de ser válido. El secreto en claro no viaja en el evento, solo el nuevo prefijo público.
 */
final readonly class IntegrationTokenRotated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $tokenPrefix,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.integration_token_rotated';
    }

    /**
     * @return array{token_prefix: string}
     */
    public function payload(): array
    {
        return [
            'token_prefix' => $this->tokenPrefix,
        ];
    }
}
