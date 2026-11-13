<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenanceStarted;

it('expone nombre y carga del evento de inicio de mantenimiento', function (): void {
    $event = new MaintenanceStarted('order-1', '2026-10-02T09:00:00+00:00');

    expect($event->eventName())->toBe('fleetops.maintenance_started')
        ->and($event->startedAt)->toBe('2026-10-02T09:00:00+00:00')
        ->and($event->aggregateId())->toBe('order-1')
        ->and($event->payload())->toBe([
            'started_at' => '2026-10-02T09:00:00+00:00',
        ]);
});
