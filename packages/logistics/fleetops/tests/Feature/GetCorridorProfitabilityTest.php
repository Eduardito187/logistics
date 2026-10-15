<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Query\GetCorridorProfitability\GetCorridorProfitability;
use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityView;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Rentabilidad por corredor (ROADMAP 3-06) contra PostgreSQL (logistics_test): margen = ingreso − costo.
 * Sin ingreso el margen queda null (no se inventa ingreso); con ingreso provisto se computa el margen.
 *
 * Reutiliza el helper fleetopsCostTelemetryTrip() declarado en GetCorridorCostBreakdownTest (Pest incluye
 * todos los archivos de prueba antes de correr, así que la función global está disponible).
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Siembra un corredor 'LPZ-01' con costo 20 000 centavos (400 c/km × 50 km) y 2 entregas, para un vehículo.
 */
function fleetopsSeedProfitabilityCorridor(string $vehicleId): void
{
    RefuelFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'total_cost_cents' => 40_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);
    fleetopsCostTelemetryTrip($vehicleId, 100, '2026-10-15 08:00:00'); // 400 centavos/km

    DepartureFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'route_ref' => 'LPZ-01',
        'status' => 'returned',
        'odometer_out_km' => 1_000,
        'odometer_in_km' => 1_050,
        'load_items' => [['name' => 'Box', 'qty' => 1], ['name' => 'Pallet', 'qty' => 1]],
        'departed_at' => '2026-10-12 06:00:00',
        'returned_at' => '2026-10-12 12:00:00',
    ]);
}

it('reports cost only and null margin when no revenue is provided', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000d001';
    fleetopsSeedProfitabilityCorridor($vehicleId);

    $view = app(QueryBus::class)->ask(new GetCorridorProfitability('2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(CorridorProfitabilityView::class)
        ->and($view->revenueAvailable)->toBeFalse()
        ->and($view->totalCostCents)->toBe(20_000)
        ->and($view->totalRevenueCents)->toBeNull()
        ->and($view->totalMarginCents)->toBeNull()
        ->and($view->lines)->toHaveCount(1);

    $line = $view->lines[0];
    expect($line->corridor)->toBe('LPZ-01')
        ->and($line->deliveries)->toBe(2)
        ->and($line->costCents)->toBe(20_000)
        ->and($line->revenueCents)->toBeNull()
        ->and($line->marginCents)->toBeNull()
        ->and($line->marginRatio)->toBeNull();
});

it('computes margin when revenue is provided per corridor', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000d002';
    fleetopsSeedProfitabilityCorridor($vehicleId);

    // Ingreso provisto (p. ej. desde settings): 30 000 centavos para el corredor LPZ-01.
    $view = app(QueryBus::class)->ask(new GetCorridorProfitability(
        '2026-10-01',
        '2026-10-31',
        ['LPZ-01' => 30_000],
    ));

    expect($view->revenueAvailable)->toBeTrue()
        ->and($view->totalCostCents)->toBe(20_000)
        ->and($view->totalRevenueCents)->toBe(30_000)
        ->and($view->totalMarginCents)->toBe(10_000);

    $line = $view->lines[0];
    expect($line->revenueCents)->toBe(30_000)
        ->and($line->marginCents)->toBe(10_000) // 30 000 − 20 000
        ->and($line->marginRatio)->toBe(0.3333); // 10 000 / 30 000
});
