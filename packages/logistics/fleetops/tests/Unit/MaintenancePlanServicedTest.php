<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenancePlanServiced;

it('expone nombre y carga del evento de servicio ejecutado de plan', function (): void {
    $event = new MaintenancePlanServiced(
        'plan-1',
        100_000,
        '2026-10-01T00:00:00+00:00',
        110_000,
        '2027-03-30T00:00:00+00:00',
    );

    expect($event->eventName())->toBe('fleetops.maintenance_plan_serviced')
        ->and($event->aggregateId())->toBe('plan-1')
        ->and($event->payload())->toBe([
            'last_done_km' => 100_000,
            'last_done_at' => '2026-10-01T00:00:00+00:00',
            'next_due_km' => 110_000,
            'next_due_at' => '2027-03-30T00:00:00+00:00',
        ]);
});

it('conserva los km y la próxima fecha nulos en la carga', function (): void {
    $event = new MaintenancePlanServiced('plan-2', null, '2026-10-01T00:00:00+00:00', null, null);

    expect($event->lastDoneKm)->toBeNull()
        ->and($event->payload()['next_due_km'])->toBeNull()
        ->and($event->payload()['next_due_at'])->toBeNull();
});
