<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenanceCancelled;

it('expone nombre y carga del evento de cancelación de mantenimiento', function (): void {
    $event = new MaintenanceCancelled('order-1', '2026-10-02T09:00:00+00:00');

    expect($event->eventName())->toBe('fleetops.maintenance_cancelled')
        ->and($event->cancelledAt)->toBe('2026-10-02T09:00:00+00:00')
        ->and($event->aggregateId())->toBe('order-1')
        ->and($event->payload())->toBe([
            'cancelled_at' => '2026-10-02T09:00:00+00:00',
        ]);
});
