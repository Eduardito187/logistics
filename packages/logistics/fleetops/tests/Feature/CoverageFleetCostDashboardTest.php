<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\FleetOps\Presentation\Filament\Pages\FleetCostDashboard;
use Logistics\FleetOps\Presentation\Filament\Support\VehiclePlates;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura del cockpit de salud de flota (FleetCostDashboard, el archivo de mayor deuda). Montar la
 * página ejecuta getViewData() y sus proyecciones (mantenimientos vencidos, documentos por vencer,
 * odómetros atrasados, KPI de combustible). Luego se llaman las acciones de costo para ejercitar los
 * formateadores de desglose por vehículo/corredor/tercero y rentabilidad (Patrón A del brief).
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
});

/**
 * Inserta un vehículo de flota mínimo para que las proyecciones cross-context resuelvan placas.
 */
function fleetopsDashboardVehicle(string $plate = 'DASH-001'): string
{
    $id = (string) Str::uuid7();

    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => $plate,
        'capacity_kg' => 5_000,
        'capacity_m3' => 20.000,
        'home_base_node_id' => (string) Str::uuid7(),
        'status' => 'active',
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // VehiclePlates memoiza el mapa id→placa en una propiedad estática (una vez por request). En la suite
    // ese estado persiste entre tests, así que se invalida tras sembrar para que el Select buscable
    // `vehicle_id` reconozca el vehículo recién creado y no falle la validación de la acción.
    $map = new ReflectionProperty(VehiclePlates::class, 'map');
    $map->setValue(null, null);

    return $id;
}

it('renderiza el cockpit sin datos (tolerante a tablas vacías)', function (): void {
    Livewire::test(FleetCostDashboard::class)->assertOk();
});

it('renderiza el cockpit con excepciones y KPIs sembrados', function (): void {
    $vehicleId = fleetopsDashboardVehicle('DASH-010');

    // Precio de diésel vigente para el KPI de combustible.
    FuelPriceFactory::new()->forFuelType('diesel')->priced(372)->effectiveFrom('2026-01-01')->create();

    // Gasto de combustible de hoy (alimenta GetDailyFuelSpend).
    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'fuel_type' => 'diesel',
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
        'refueled_at' => now()->format('Y-m-d').' 08:00:00',
    ]);

    // Plan preventivo por km ya vencido (alimenta ListDueMaintenance) con odómetro base.
    MaintenancePlanFactory::new()->forVehicle($vehicleId)->create([
        'interval_km' => 10_000,
        'interval_days' => 180,
        'last_done_km' => 100_000,
        'last_done_at' => '2025-01-01 09:00:00',
        'next_due_km' => 105_000,
        'next_due_at' => '2025-06-01 09:00:00',
        'active' => true,
    ]);

    Livewire::test(FleetCostDashboard::class)->assertOk();
});

it('ejecuta la acción de resumen de costos por vehículo', function (): void {
    $vehicleId = fleetopsDashboardVehicle('DASH-020');

    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'total_cost_cents' => 40_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);
    MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(16_000)->create([
        'completed_at' => '2026-10-11 10:00:00',
    ]);

    // Montar la acción ejecuta su schema() (Select buscable de vehículo + rango de fechas). El form buscable
    // de una acción no es manejable por los helpers de test de Filament v4 (descarta valores no precargados),
    // así que el cálculo de costos se cubre por su query dedicada en CoverageQueriesTest, no por esta acción.
    Livewire::test(FleetCostDashboard::class)
        ->mountAction('cost_summary')
        ->assertActionMounted('cost_summary')
        ->assertOk();
});

it('ejecuta la acción de costo y rentabilidad por rango', function (): void {
    $vehicleId = fleetopsDashboardVehicle('DASH-030');

    // Costo del vehículo en el período + telemetría para el costo por km del desglose.
    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'total_cost_cents' => 40_000,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);
    DB::table('telemetry_trips')->insert([
        'id' => (string) Str::uuid7(),
        'vehicle_id' => $vehicleId,
        'status' => 'ended',
        'started_at' => '2026-10-15 08:00:00',
        'ended_at' => '2026-10-15 09:00:00',
        'start_lat' => -16.5000000,
        'start_lng' => -68.1500000,
        'end_lat' => -16.5100000,
        'end_lng' => -68.1600000,
        'distance_m' => 100_000,
        'duration_s' => 3600,
        'moving_s' => 3000,
        'idle_s' => 600,
        'max_speed_kph' => 80.0,
        'ping_count' => 120,
    ]);

    // Liquidación de un tercero para el desglose por transportista.
    $carrierId = (string) Str::uuid7();
    DB::table('carriers')->insert([
        'id' => $carrierId,
        'name' => 'Trans Andina',
        'tax_id' => 'TAX-'.Str::random(8),
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
        'corridor_to' => 'SCZ',
        'corridor_type' => 'intercity',
        'period' => '2026-10',
        'amount_cents' => 50_000,
        'currency' => 'BOB',
        'reference' => 'INV-001',
        'status' => 'approved',
        'dispute_reason' => null,
        'payment_reference' => null,
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test(FleetCostDashboard::class)
        ->callAction('cost_breakdown', data: [
            'from' => '2026-10-01',
            'to' => '2026-10-31',
        ])
        ->assertHasNoActionErrors()
        ->assertOk();
});

it('ejecuta la acción de registrar odómetro (comando CQRS)', function (): void {
    $vehicleId = fleetopsDashboardVehicle('DASH-040');

    // Montar la acción cubre su schema(); el efecto transaccional (RecordOdometer → handler + repositorio)
    // se cubre despachando el comando directamente (el form buscable de la acción no es driveable en tests).
    Livewire::test(FleetCostDashboard::class)
        ->mountAction('recordOdometer')
        ->assertActionMounted('recordOdometer')
        ->assertOk();

    app(CommandBus::class)->dispatch(new RecordOdometer(
        vehicleId: $vehicleId,
        km: 123_456,
        source: OdometerSource::Manual->value,
        readAt: '2026-10-14 10:00:00',
        note: 'Lectura manual',
    ));

    expect(DB::table('fleetops_odometer_readings')->where('vehicle_id', $vehicleId)->count())->toBe(1);
});

it('ejecuta la acción de agendar mantenimiento (comando CQRS)', function (): void {
    $vehicleId = fleetopsDashboardVehicle('DASH-050');

    // Montar la acción cubre su schema(); el efecto transaccional (ScheduleMaintenance → handler + repo) se
    // cubre despachando el comando directamente (el form buscable de la acción no es driveable en tests).
    Livewire::test(FleetCostDashboard::class)
        ->mountAction('scheduleMaintenance')
        ->assertActionMounted('scheduleMaintenance')
        ->assertOk();

    app(CommandBus::class)->dispatch(new ScheduleMaintenance(
        vehicleId: $vehicleId,
        type: MaintenanceType::Preventive->value,
        odometerKm: 100_000,
        workshop: 'Taller Central',
        scheduledAt: '2026-10-20 08:00:00',
        note: null,
    ));

    expect(DB::table('fleetops_maintenance_orders')->where('vehicle_id', $vehicleId)->count())->toBe(1);
});
