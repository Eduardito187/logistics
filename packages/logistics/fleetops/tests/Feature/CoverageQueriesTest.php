<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\FleetOps\Application\Query\GetCarrierCostBreakdown\GetCarrierCostBreakdown;
use Logistics\FleetOps\Application\Query\GetDailyFuelSpend\GetDailyFuelSpend;
use Logistics\FleetOps\Application\Query\GetFuelConsumption\GetFuelConsumption;
use Logistics\FleetOps\Application\Query\GetMaintenanceCost\GetMaintenanceCost;
use Logistics\FleetOps\Application\Query\GetVehicleCostBreakdown\GetVehicleCostBreakdown;
use Logistics\FleetOps\Application\Query\GetVehicleProfile\GetVehicleProfile;
use Logistics\FleetOps\Application\Query\GetVehicleUtilization\GetVehicleUtilization;
use Logistics\FleetOps\Application\Query\ListDepartures\ListDepartures;
use Logistics\FleetOps\Application\Query\ListExpenses\ListExpenses;
use Logistics\FleetOps\Application\Query\ListFuelPrices\ListFuelPrices;
use Logistics\FleetOps\Application\Query\ListMaintenanceOrders\ListMaintenanceOrders;
use Logistics\FleetOps\Application\Query\ListMaintenancePlans\ListMaintenancePlans;
use Logistics\FleetOps\Application\Query\ListOdometerReadings\ListOdometerReadings;
use Logistics\FleetOps\Application\Query\ListRefuels\ListRefuels;
use Logistics\FleetOps\Application\Query\ListVehicleProfiles\ListVehicleProfiles;
use Logistics\FleetOps\Application\ReadModel\CarrierCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\FuelConsumptionView;
use Logistics\FleetOps\Application\ReadModel\MaintenanceCostView;
use Logistics\FleetOps\Application\ReadModel\UtilizationView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\VehicleProfileView;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\DepartureFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\ExpenseFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\OdometerReadingFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Cobertura de los handlers de consulta y sus ReadModels (Patrón C del brief): se siembran filas reales
 * con las fábricas del contexto y se despachan las consultas por el QueryBus, ejercitando el mapeo de
 * dominio a proyección (fromDomain/toArray) y los agregados de costo/consumo/utilización.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('lista las salidas y proyecta la vista de salida', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d001';
    DepartureFactory::new()->forVehicle($vehicleId)->returned(120)->create();

    $views = app(QueryBus::class)->ask(new ListDepartures($vehicleId));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->vehicleId)->toBe($vehicleId)
        ->and($views[0]->toArray())->toHaveKey('km_traveled');
});

it('lista las cargas de combustible y proyecta la vista de carga', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d002';
    RefuelFactory::new()->forVehicle($vehicleId)->create(['refueled_at' => '2026-10-10 08:00:00']);

    $views = app(QueryBus::class)->ask(new ListRefuels($vehicleId, '2026-10-01', '2026-10-31'));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('total_cost_cents');
});

it('lista los gastos y proyecta la vista de gasto', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d003';
    ExpenseFactory::new()->forVehicle($vehicleId)->priced(5_000)->create(['incurred_at' => '2026-10-05 09:00:00']);

    $views = app(QueryBus::class)->ask(new ListExpenses($vehicleId, null, '2026-10-01', '2026-10-31'));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('amount_cents');
});

it('lista los precios de combustible y proyecta la vista de precio', function (): void {
    FuelPriceFactory::new()->forFuelType('diesel')->priced(372)->create();

    $views = app(QueryBus::class)->ask(new ListFuelPrices('diesel'));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('price_cents');
});

it('lista las órdenes de mantenimiento y proyecta la vista de orden', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d004';
    MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(17_000)->create();

    $views = app(QueryBus::class)->ask(new ListMaintenanceOrders($vehicleId));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('total_cost_cents');
});

it('lista los planes de mantenimiento y proyecta la vista de plan', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d005';
    MaintenancePlanFactory::new()->forVehicle($vehicleId)->create();

    $views = app(QueryBus::class)->ask(new ListMaintenancePlans($vehicleId));

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('interval_km');
});

it('lista las lecturas de odómetro y proyecta la vista de lectura', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d006';
    OdometerReadingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-10 08:00:00'))->create();

    $views = app(QueryBus::class)->ask(new ListOdometerReadings($vehicleId, '2026-10-01', '2026-10-31'));

    expect($views)->toBeArray()->not->toBeEmpty();
});

it('lista los perfiles de vehículo y proyecta la vista de perfil', function (): void {
    VehicleProfileFactory::new()->create();

    $views = app(QueryBus::class)->ask(new ListVehicleProfiles);

    expect($views)->toBeArray()->not->toBeEmpty()
        ->and($views[0]->toArray())->toHaveKey('brand');
});

it('obtiene el perfil técnico de un vehículo concreto', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d007';
    VehicleProfileFactory::new()->forVehicle($vehicleId)->withOdometer(123_000)->create();

    $view = app(QueryBus::class)->ask(new GetVehicleProfile($vehicleId));

    expect($view)->toBeInstanceOf(VehicleProfileView::class)
        ->and($view->vehicleId)->toBe($vehicleId);
});

it('calcula el consumo de combustible tanque a tanque', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d008';

    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'liters' => 50.0,
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
        'odometer_km' => 100_000,
        'full_tank' => true,
        'refueled_at' => '2026-10-05 08:00:00',
    ]);
    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'liters' => 48.0,
        'total_cost_cents' => 17_856,
        'currency' => 'BOB',
        'odometer_km' => 100_600,
        'full_tank' => true,
        'refueled_at' => '2026-10-12 08:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetFuelConsumption($vehicleId, '2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(FuelConsumptionView::class)
        ->and($view->kmTraveled)->toBe(600)
        ->and($view->kmPerLiter)->not->toBeNull();
});

it('calcula la utilización de un vehículo en el rango', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d009';
    DepartureFactory::new()->forVehicle($vehicleId)->returned(300)->create(['departed_at' => '2026-10-08 06:00:00']);
    OdometerReadingFactory::new()->forVehicleAt($vehicleId, new DateTimeImmutable('2026-10-10 08:00:00'))->create(['km' => 120_000]);

    $view = app(QueryBus::class)->ask(new GetVehicleUtilization($vehicleId, '2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(UtilizationView::class)
        ->and($view->departuresCount)->toBeGreaterThanOrEqual(1);
});

it('calcula el costo de mantenimiento completado en el rango', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d010';
    MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(16_000)->create([
        'completed_at' => '2026-10-11 10:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetMaintenanceCost($vehicleId, '2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(MaintenanceCostView::class)
        ->and($view->totalCostCents)->toBe(16_000);
});

it('calcula el gasto diario de combustible', function (): void {
    $vehicleId = '019299cc-0000-7000-8000-00000000d011';
    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetDailyFuelSpend('2026-10-10'));

    expect($view->totalCostCents)->toBe(18_600);
});

it('desglosa el costo de la flota por vehículo', function (): void {
    $vehicleId = (string) Str::uuid7();
    DB::table('fleet_vehicles')->insert([
        'id' => $vehicleId,
        'plate' => 'COST-001',
        'capacity_kg' => 5_000,
        'capacity_m3' => 20.000,
        'home_base_node_id' => (string) Str::uuid7(),
        'status' => 'active',
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'total_cost_cents' => 40_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);

    $view = app(QueryBus::class)->ask(new GetVehicleCostBreakdown('2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(VehicleCostBreakdownView::class)
        ->and($view->totalCents)->toBeGreaterThanOrEqual(40_000);
});

it('desglosa el costo de terceros por transportista', function (): void {
    $carrierId = (string) Str::uuid7();
    DB::table('carriers')->insert([
        'id' => $carrierId,
        'name' => 'Trans Oriente',
        'tax_id' => 'TAX-'.Str::random(10),
        'status' => 'active',
        'rating' => null,
        'rating_count' => 0,
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('carrier_settlements')->insert([
        'id' => (string) Str::uuid7(),
        'carrier_id' => $carrierId,
        'corridor_from' => 'LPZ',
        'corridor_to' => 'CBB',
        'corridor_type' => 'intercity',
        'period' => '2026-10',
        'amount_cents' => 75_000,
        'currency' => 'BOB',
        'reference' => 'INV-777',
        'status' => 'approved',
        'dispute_reason' => null,
        'payment_reference' => null,
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $view = app(QueryBus::class)->ask(new GetCarrierCostBreakdown('2026-10-01', '2026-10-31'));

    expect($view)->toBeInstanceOf(CarrierCostBreakdownView::class)
        ->and($view->totalCents)->toBe(75_000)
        ->and($view->lines)->toHaveCount(1)
        ->and($view->lines[0]->carrierName)->toBe('Trans Oriente');
});
