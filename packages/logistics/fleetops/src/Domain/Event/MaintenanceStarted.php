<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Una orden de mantenimiento pasó a estar en curso (el vehículo entró al taller).
 */
final readonly class MaintenanceStarted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $startedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_started';
    }

    public function payload(): array
    {
        return [
            'started_at' => $this->startedAt,
        ];
    }
}
