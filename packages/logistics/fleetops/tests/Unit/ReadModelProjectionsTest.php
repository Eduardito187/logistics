<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\ReadModel\CarrierCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CarrierCostLineView;
use Logistics\FleetOps\Application\ReadModel\CorridorCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CorridorCostLineView;
use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityLineView;
use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityView;
use Logistics\FleetOps\Application\ReadModel\DailyFuelSpendView;
use Logistics\FleetOps\Application\ReadModel\FuelConsumptionView;
use Logistics\FleetOps\Application\ReadModel\OdometerView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostLineView;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;

/**
 * Proyecciones de lectura (ReadModels) puras: construye cada vista con datos primitivos y afirma su
 * `toArray()`, incluido el mapeo de las líneas anidadas. Sin Laravel ni base de datos.
 */
it('proyecta el desglose de costo de terceros con su línea', function (): void {
    $line = new CarrierCostLineView('carrier-1', 'Trans Oriente', 2, 3, 75_000, 'BOB');
    $view = new CarrierCostBreakdownView('2026-10-01', '2026-10-31', 75_000, 'BOB', [$line]);

    expect($line->toArray())->toBe([
        'carrier_id' => 'carrier-1',
        'carrier_name' => 'Trans Oriente',
        'settlements' => 2,
        'corridors' => 3,
        'cost_cents' => 75_000,
        'currency' => 'BOB',
    ])
        ->and($view->toArray())->toMatchArray([
            'total_cents' => 75_000,
            'currency' => 'BOB',
        ])
        ->and($view->toArray()['lines'])->toHaveCount(1);
});

it('proyecta el desglose de costo de la flota por vehículo con su línea', function (): void {
    $lineView = new VehicleCostLineView('veh-1', 40_000, 16_000, 5_000, 61_000, 600, 101, 'BOB');
    $view = new VehicleCostBreakdownView('2026-10-01', '2026-10-31', 61_000, 'BOB', [$lineView]);

    expect($lineView->toArray())->toMatchArray([
        'vehicle_id' => 'veh-1',
        'fuel_cents' => 40_000,
        'maintenance_cents' => 16_000,
        'expenses_cents' => 5_000,
        'total_cents' => 61_000,
        'kilometers' => 600,
        'cost_per_km_cents' => 101,
    ])
        ->and($view->toArray()['total_cents'])->toBe(61_000)
        ->and($view->toArray()['lines'])->toHaveCount(1);
});

it('proyecta el desglose de costo por corredor con su línea', function (): void {
    $line = new CorridorCostLineView('LPZ-CBB', 5, 1_800, 40, 90_000, 50, 2_250, 'BOB');
    $view = new CorridorCostBreakdownView('2026-10-01', '2026-10-31', 90_000, 'BOB', [$line]);

    expect($line->toArray())->toMatchArray([
        'corridor' => 'LPZ-CBB',
        'departures' => 5,
        'kilometers' => 1_800,
        'deliveries' => 40,
        'cost_cents' => 90_000,
        'cost_per_km_cents' => 50,
        'cost_per_delivery_cents' => 2_250,
    ])
        ->and($view->toArray()['total_cents'])->toBe(90_000)
        ->and($view->toArray()['lines'])->toHaveCount(1);
});

it('proyecta la rentabilidad por corredor con su línea', function (): void {
    $line = new CorridorProfitabilityLineView('LPZ-CBB', 40, 90_000, 150_000, 60_000, 0.4, 'BOB');
    $view = new CorridorProfitabilityView('2026-10-01', '2026-10-31', 90_000, 150_000, 60_000, 'BOB', true, [$line]);

    expect($line->toArray())->toMatchArray([
        'corridor' => 'LPZ-CBB',
        'deliveries' => 40,
        'cost_cents' => 90_000,
        'revenue_cents' => 150_000,
        'margin_cents' => 60_000,
        'currency' => 'BOB',
    ])
        ->and($line->toArray()['margin_ratio'])->toEqualWithDelta(0.4, 0.0001)
        ->and($view->toArray())->toMatchArray([
            'total_cost_cents' => 90_000,
            'total_revenue_cents' => 150_000,
            'total_margin_cents' => 60_000,
            'revenue_available' => true,
        ])
        ->and($view->toArray()['lines'])->toHaveCount(1);
});

it('proyecta la rentabilidad por corredor sin ingreso (margen nulo)', function (): void {
    $view = new CorridorProfitabilityView('2026-10-01', '2026-10-31', 90_000, null, null, 'BOB', false, []);

    expect($view->toArray())->toMatchArray([
        'total_revenue_cents' => null,
        'total_margin_cents' => null,
        'revenue_available' => false,
        'lines' => [],
    ]);
});

it('proyecta el gasto diario de combustible', function (): void {
    $view = new DailyFuelSpendView('2026-10-10', 18_600, 'BOB', [
        ['vehicle_id' => 'veh-1', 'total_cost_cents' => 18_600, 'liters' => 50.0],
    ]);

    expect($view->toArray())->toMatchArray([
        'date' => '2026-10-10',
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
    ])
        ->and($view->toArray()['by_vehicle'])->toHaveCount(1);
});

it('proyecta el consumo de combustible', function (): void {
    $view = new FuelConsumptionView('veh-1', '2026-10-01', '2026-10-31', 98.0, 36_456, 'BOB', 600, 6.12, 60);

    $array = $view->toArray();
    expect($array)->toMatchArray([
        'vehicle_id' => 'veh-1',
        'total_cost_cents' => 36_456,
        'currency' => 'BOB',
        'km_traveled' => 600,
        'cost_per_km_cents' => 60,
    ])
        ->and($array['total_liters'])->toEqualWithDelta(98.0, 0.0001)
        ->and($array['km_per_liter'])->toEqualWithDelta(6.12, 0.0001);
});

it('proyecta una lectura de odómetro desde el dominio', function (): void {
    $reading = OdometerReading::record(
        OdometerReadingId::generate(),
        'veh-1',
        Odometer::km(120_000),
        OdometerSource::Manual,
        new DateTimeImmutable('2026-10-10T08:00:00+00:00'),
        'nota',
    );

    $view = OdometerView::fromDomain($reading);

    expect($view->toArray())->toMatchArray([
        'vehicle_id' => 'veh-1',
        'km' => 120_000,
        'source' => OdometerSource::Manual->value,
        'note' => 'nota',
    ])
        ->and($view->toArray()['read_at'])->toContain('2026-10-10');
});
