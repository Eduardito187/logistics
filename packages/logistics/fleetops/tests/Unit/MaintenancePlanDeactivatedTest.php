<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenancePlanDeactivated;

it('expone nombre y carga vacía del evento de desactivación de plan', function (): void {
    $event = new MaintenancePlanDeactivated('plan-2');

    expect($event->eventName())->toBe('fleetops.maintenance_plan_deactivated')
        ->and($event->payload())->toBe([])
        ->and($event->aggregateId())->toBe('plan-2');
});
