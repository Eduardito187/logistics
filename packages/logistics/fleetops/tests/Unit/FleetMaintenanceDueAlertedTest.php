<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FleetMaintenanceDueAlerted;

it('expone nombre y carga del resumen de mantenimiento por vencer', function (): void {
    $items = [[
        'vehicle_id' => 'veh-1',
        'service_kind' => 'oil_change',
        'next_due_km' => 110_000,
        'next_due_at' => '2026-10-10',
        'current_odometer_km' => 115_000,
        'overdue' => true,
    ]];

    $event = new FleetMaintenanceDueAlerted('fleetops-maintenance-due-2026-10-05', '2026-10-05', 1, 2, $items);

    expect($event->eventName())->toBe('fleetops.maintenance_due_alerted')
        ->and($event->aggregateId())->toBe('fleetops-maintenance-due-2026-10-05')
        ->and($event->overdueCount)->toBe(1)
        ->and($event->dueSoonCount)->toBe(2)
        ->and($event->payload())->toBe([
            'as_of' => '2026-10-05',
            'overdue_count' => 1,
            'due_soon_count' => 2,
            'items' => $items,
        ]);
});
