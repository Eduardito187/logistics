<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenancePlanActivated;

it('expone nombre y carga vacía del evento de activación de plan', function (): void {
    $event = new MaintenancePlanActivated('plan-1');

    expect($event->eventName())->toBe('fleetops.maintenance_plan_activated')
        ->and($event->payload())->toBe([])
        ->and($event->aggregateId())->toBe('plan-1');
});
