<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\Command\CompleteMaintenance\CompleteMaintenance;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Flujo real de mantenimiento contra PostgreSQL (logistics_test): se agenda una orden, se completa con sus
 * ítems y el agregado calcula el costo total sumando los `lineTotal()`; cada mutación deja eventos en el outbox.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('computes total_cost when completing a maintenance order and records outbox events', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000f002';
    $bus = app(CommandBus::class);

    $bus->dispatch(new ScheduleMaintenance(
        vehicleId: $vehicleId,
        type: 'preventive',
        odometerKm: 100_000,
        workshop: 'Taller Central',
        scheduledAt: '2026-10-01T08:00:00+00:00',
    ));

    $orderId = (string) MaintenanceOrderRecord::query()->where('vehicle_id', $vehicleId)->value('id');
    expect($orderId)->not->toBe('');

    $bus->dispatch(new CompleteMaintenance(
        orderId: $orderId,
        items: [
            ['description' => 'Oil change', 'qty' => 2, 'part_cost_cents' => 5_000, 'labor_cost_cents' => 3_000],
            ['description' => 'Filter', 'qty' => 1, 'part_cost_cents' => 1_200, 'labor_cost_cents' => 800],
        ],
        currency: 'BOB',
    ));

    $order = MaintenanceOrderRecord::query()->findOrFail($orderId);

    // (5000 + 3000) × 2 + (1200 + 800) × 1 = 16000 + 2000 = 18000.
    expect($order->status)->toBe(MaintenanceStatus::Completed->value)
        ->and((int) $order->total_cost_cents)->toBe(18_000);

    expect(DB::table('outbox_messages')->where('aggregate_type', 'fleetops.maintenance_order')->count())->toBeGreaterThanOrEqual(2);
});
