<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\Query\GetCorridorCostBreakdown\GetCorridorCostBreakdown;
use Logistics\FleetOps\Application\ReadModel\CorridorCostBreakdownView;
use Logistics\FleetOps\Application\Service\CorridorCostCalculator;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Modelo de costos por corredor (ROADMAP 3-01) contra PostgreSQL (logistics_test): el costo de cada
 * salida se atribuye como km recorridos × costo por km del vehículo (combustible/mantenimiento/gastos /
 * km de telemetría), y se agrega por corredor (route_ref, o destination si falta).
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Inserta un trip de telemetría con los km dados para un vehículo (da base al costo por km).
 */
function fleetopsCostTelemetryTrip(string $vehicleId, int $km, string $startedAt): void
{
    DB::table('telemetry_trips')->insert([
        'id' => Uuid::generate()->value,
        'vehicle_id' => $vehicleId,
        'status' => 'ended',
        'started_at' => $startedAt,
        'ended_at' => $startedAt,
        'start_lat' => -16.5000000,
        'start_lng' => -68.1500000,
        'end_lat' => -16.5100000,
        'end_lng' => -68.1600000,
        'distance_m' => $km * 1000,
        'duration_s' => 3600,
        'moving_s' => 3000,
        'idle_s' => 600,
        'max_speed_kph' => 80.0,
        'ping_count' => 120,
    ]);
}

it('attributes cost per corridor from vehicle cost per km and departure distance', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000c001';

    // Costo del vehículo en el período: 40 000 centavos (solo combustible, para un costo por km limpio).
    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'total_cost_cents' => 40_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);

    // 100 km de telemetría ⇒ costo por km = 40 000 / 100 = 400 centavos/km.
    fleetopsCostTelemetryTrip($vehicleId, 100, '2026-10-15 08:00:00');

    // Corredor 'LPZ-01': dos salidas cerradas (40 km / 2 entregas, 10 km / sin ítems → 1 entrega).
    DepartureFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'route_ref' => 'LPZ-01',
        'status' => 'returned',
        'odometer_out_km' => 1_000,
        'odometer_in_km' => 1_040,
        'load_items' => [['name' => 'Box', 'qty' => 1], ['name' => 'Pallet', 'qty' => 1]],
        'departed_at' => '2026-10-12 06:00:00',
        'returned_at' => '2026-10-12 12:00:00',
    ]);
    DepartureFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'route_ref' => 'LPZ-01',
        'status' => 'returned',
        'odometer_out_km' => 2_000,
        'odometer_in_km' => 2_010,
        'load_items' => [],
        'departed_at' => '2026-10-13 06:00:00',
        'returned_at' => '2026-10-13 10:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetCorridorCostBreakdown('2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(CorridorCostBreakdownView::class)
        ->and($view->lines)->toHaveCount(1);

    $line = $view->lines[0];

    // Costo = 400×40 + 400×10 = 20 000; km = 50; entregas = 2 + 1 = 3.
    expect($line->corridor)->toBe('LPZ-01')
        ->and($line->departures)->toBe(2)
        ->and($line->kilometers)->toBe(50)
        ->and($line->deliveries)->toBe(3)
        ->and($line->costCents)->toBe(20_000)
        ->and($line->costPerKmCents)->toBe(400)
        ->and($line->costPerDeliveryCents)->toBe(6_667) // 20 000 / 3 ≈ 6 666.67 → 6 667
        ->and($view->totalCents)->toBe(20_000);
});

it('falls back to the destination as corridor key when there is no route ref', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000c002';

    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'total_cost_cents' => 20_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);
    fleetopsCostTelemetryTrip($vehicleId, 100, '2026-10-15 08:00:00'); // 200 centavos/km

    DepartureFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'route_ref' => null,
        'destination' => 'Cochabamba',
        'status' => 'returned',
        'odometer_out_km' => 5_000,
        'odometer_in_km' => 5_020,
        'load_items' => [],
        'departed_at' => '2026-10-14 06:00:00',
        'returned_at' => '2026-10-14 10:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetCorridorCostBreakdown('2026-10-01', '2026-10-31'));

    expect($view->lines)->toHaveCount(1)
        ->and($view->lines[0]->corridor)->toBe('Cochabamba')
        ->and($view->lines[0]->kilometers)->toBe(20)
        ->and($view->lines[0]->costCents)->toBe(4_000); // 200 × 20
});

it('is reusable through the shared corridor cost calculator', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000c003';

    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'total_cost_cents' => 10_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);
    fleetopsCostTelemetryTrip($vehicleId, 50, '2026-10-15 08:00:00'); // 200 centavos/km

    DepartureFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'route_ref' => 'SCZ-09',
        'status' => 'returned',
        'odometer_out_km' => 100,
        'odometer_in_km' => 130,
        'load_items' => [],
        'departed_at' => '2026-10-16 06:00:00',
        'returned_at' => '2026-10-16 10:00:00',
    ]);

    $rows = app(CorridorCostCalculator::class)->breakdown('2026-10-01', '2026-10-31');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['corridor'])->toBe('SCZ-09')
        ->and($rows[0]['cost_cents'])->toBe(6_000); // 200 × 30
});
