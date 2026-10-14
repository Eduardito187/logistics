<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Query\GetVehicleCostSummary\GetVehicleCostSummary;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\ExpenseFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Query transversal de costos contra PostgreSQL (logistics_test): el resumen de un vehículo suma el
 * combustible, el mantenimiento completado y los gastos del período. Sin telemetría, los km quedan en 0 y
 * el costo por km en nulo.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('sums fuel, maintenance and expenses of a vehicle over the period', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000f003';

    // Combustible del período: 18600 centavos.
    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'fuel_type' => 'diesel',
        'liters' => 50.0,
        'price_per_liter_cents' => 372,
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);

    // Mantenimiento completado dentro del período: 16000 centavos.
    MaintenanceOrderFactory::new()->completed(16_000)->create([
        'vehicle_id' => $vehicleId,
        'completed_at' => '2026-10-11 10:00:00',
    ]);

    // Gasto del período: 5000 centavos.
    ExpenseFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'category' => 'toll',
        'amount_cents' => 5_000,
        'currency' => 'BOB',
        'incurred_at' => '2026-10-05 09:00:00',
    ]);

    // Ruido fuera del período: no debe contarse.
    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'total_cost_cents' => 99_999,
        'currency' => 'BOB',
        'refueled_at' => '2026-09-01 08:00:00',
    ]);

    $summary = app(QueryBus::class)->ask(new GetVehicleCostSummary($vehicleId, '2026-10-01', '2026-10-31'));

    expect($summary)->toBeInstanceOf(VehicleCostSummaryView::class)
        ->and($summary->fuelCents)->toBe(18_600)
        ->and($summary->maintenanceCents)->toBe(16_000)
        ->and($summary->expensesCents)->toBe(5_000)
        ->and($summary->totalCents)->toBe(39_600)
        ->and($summary->currency)->toBe('BOB')
        ->and($summary->kilometers)->toBe(0)
        ->and($summary->costPerKmCents)->toBeNull();
});
