<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un plan de mantenimiento se desactivó: deja de vigilar vencimientos (p. ej. vehículo de baja).
 */
final readonly class MaintenancePlanDeactivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'fleetops.maintenance_plan_deactivated';
    }

    public function payload(): array
    {
        return [];
    }
}
