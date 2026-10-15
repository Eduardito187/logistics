<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un plan de mantenimiento se activó: vuelve a vigilar vencimientos del vehículo.
 */
final readonly class MaintenancePlanActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'fleetops.maintenance_plan_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
