<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró la ejecución del servicio de un plan: fija el último servicio y recalcula el próximo
 * vencimiento (por km y/o por fecha).
 */
final readonly class MaintenancePlanServiced extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?int $lastDoneKm,
        public string $lastDoneAt,
        public ?int $nextDueKm,
        public ?string $nextDueAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_plan_serviced';
    }

    public function payload(): array
    {
        return [
            'last_done_km' => $this->lastDoneKm,
            'last_done_at' => $this->lastDoneAt,
            'next_due_km' => $this->nextDueKm,
            'next_due_at' => $this->nextDueAt,
        ];
    }
}
