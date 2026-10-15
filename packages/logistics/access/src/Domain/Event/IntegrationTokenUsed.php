<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se usó un token de integración para autenticar una petición. Registra el momento del último uso.
 */
final readonly class IntegrationTokenUsed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public DateTimeImmutable $at,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.integration_token_used';
    }

    /**
     * @return array{at: string}
     */
    public function payload(): array
    {
        return [
            'at' => $this->at->format(DateTimeInterface::ATOM),
        ];
    }
}
