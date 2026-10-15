<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambió la disponibilidad de un ayudante (se activó o se dio de baja).
 */
final readonly class HelperAvailabilityChanged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public bool $active,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.helper_availability_changed';
    }

    public function payload(): array
    {
        return ['active' => $this->active];
    }
}
