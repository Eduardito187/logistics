<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una orden de mantenimiento se canceló (no se ejecutará).
 */
final readonly class MaintenanceCancelled extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $cancelledAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_cancelled';
    }

    public function payload(): array
    {
        return [
            'cancelled_at' => $this->cancelledAt,
        ];
    }
}
