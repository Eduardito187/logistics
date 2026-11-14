<?php

declare(strict_types=1);

use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\FuelPriceFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenanceOrderFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\MaintenancePlanFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\RefuelFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory\VehicleProfileFactory;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP de la puerta app de fleetops que la suite aún no recorría (Patrón F):
 * las lecturas puntuales (GetCurrentFuelPrice, GetFuelConsumption, GetDailyFuelSpend, GetMaintenanceOrder,
 * GetVehicleProfile, ListVehicleProfiles) y las transiciones por ruta sobre órdenes y planes
 * (StartMaintenance, CancelMaintenance, RecordServiceDone, ActivatePlan, DeactivatePlan). Se omite el
 * middleware de puerta y se ejercita el happy-path real (más los early-return 404) contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('devuelve el precio vigente de un combustible por su endpoint', function (): void {
    FuelPriceFactory::new()->forFuelType('diesel')->priced(372)->effectiveFrom('2026-01-01')->create();

    $this->getJson('/api/v1/app/fleetops/fuel-prices/current?fuel_type=diesel')
        ->assertOk()
        ->assertJsonPath('data.fuel_type', 'diesel')
        ->assertJsonPath('data.price_cents', 372);
});

it('devuelve 404 cuando no hay precio vigente del combustible pedido', function (): void {
    $this->getJson('/api/v1/app/fleetops/fuel-prices/current?fuel_type=gasoline_premium')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'fuel_price_not_found');
});

it('rechaza con 422 el precio vigente sin tipo de combustible', function (): void {
    $this->getJson('/api/v1/app/fleetops/fuel-prices/current')
        ->assertStatus(422);
});

it('devuelve el consumo tanque a tanque de un vehículo por su endpoint', function (): void {
    $vehicleId = Uuid::generate()->value;

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

    $this->getJson("/api/v1/app/fleetops/fuel/consumption?vehicle_id={$vehicleId}&from=2026-10-01&to=2026-10-31")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('devuelve el gasto diario de combustible por su endpoint', function (): void {
    $vehicleId = Uuid::generate()->value;

    RefuelFactory::new()->forVehicle($vehicleId)->create([
        'total_cost_cents' => 18_600,
        'currency' => 'BOB',
        'refueled_at' => '2026-10-10 08:00:00',
    ]);

    $this->getJson('/api/v1/app/fleetops/fuel/daily-spend?date=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.total_cost_cents', 18_600);
});

it('relee una orden de mantenimiento por su endpoint de detalle', function (): void {
    $vehicleId = Uuid::generate()->value;
    $record = MaintenanceOrderFactory::new()->forVehicle($vehicleId)->completed(17_000)->create();
    $orderId = (string) $record->getKey();

    $this->getJson("/api/v1/app/fleetops/maintenance/orders/{$orderId}")
        ->assertOk()
        ->assertJsonPath('data.id', $orderId)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('devuelve 404 al releer una orden de mantenimiento inexistente', function (): void {
    $this->getJson('/api/v1/app/fleetops/maintenance/orders/'.Uuid::generate()->value)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'maintenance_order_not_found');
});

it('inicia una orden de mantenimiento agendada por su endpoint', function (): void {
    $record = MaintenanceOrderFactory::new()->create();
    $orderId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/orders/{$orderId}/start")
        ->assertStatus(202)
        ->assertJsonPath('data.order_id', $orderId);

    expect(MaintenanceOrderRecord::query()->whereKey($orderId)->value('status'))->toBe('in_progress');
});

it('cancela una orden de mantenimiento agendada por su endpoint', function (): void {
    $record = MaintenanceOrderFactory::new()->create();
    $orderId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/orders/{$orderId}/cancel")
        ->assertStatus(202)
        ->assertJsonPath('data.order_id', $orderId);

    expect(MaintenanceOrderRecord::query()->whereKey($orderId)->value('status'))->toBe('cancelled');
});

it('registra el servicio hecho de un plan por su endpoint (con odómetro)', function (): void {
    $record = MaintenancePlanFactory::new()->create();
    $planId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/plans/{$planId}/service-done", [
        'odometer_km' => 110_000,
    ])->assertStatus(202)
        ->assertJsonPath('data.plan_id', $planId);

    expect((int) MaintenancePlanRecord::query()->whereKey($planId)->value('last_done_km'))->toBe(110_000);
});

it('registra el servicio hecho de un plan por su endpoint (sin odómetro)', function (): void {
    $record = MaintenancePlanFactory::new()->create();
    $planId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/plans/{$planId}/service-done", [])
        ->assertStatus(202)
        ->assertJsonPath('data.plan_id', $planId);
});

it('rechaza con 422 el servicio hecho con un odómetro negativo', function (): void {
    $record = MaintenancePlanFactory::new()->create();
    $planId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/plans/{$planId}/service-done", [
        'odometer_km' => -5,
    ])->assertStatus(422);
});

it('activa un plan de mantenimiento desactivado por su endpoint', function (): void {
    $record = MaintenancePlanFactory::new()->inactive()->create();
    $planId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/plans/{$planId}/activate")
        ->assertStatus(202)
        ->assertJsonPath('data.plan_id', $planId);

    expect((bool) MaintenancePlanRecord::query()->whereKey($planId)->value('active'))->toBeTrue();
});

it('desactiva un plan de mantenimiento activo por su endpoint', function (): void {
    $record = MaintenancePlanFactory::new()->create();
    $planId = (string) $record->getKey();

    $this->postJson("/api/v1/app/fleetops/maintenance/plans/{$planId}/deactivate")
        ->assertStatus(202)
        ->assertJsonPath('data.plan_id', $planId);

    expect((bool) MaintenancePlanRecord::query()->whereKey($planId)->value('active'))->toBeFalse();
});

it('devuelve el perfil técnico de un vehículo por su endpoint de detalle', function (): void {
    $vehicleId = Uuid::generate()->value;
    VehicleProfileFactory::new()->forVehicle($vehicleId)->withOdometer(123_000)->create();

    $this->getJson("/api/v1/app/fleetops/vehicle-profiles/{$vehicleId}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('devuelve 404 al releer el perfil técnico de un vehículo sin perfil', function (): void {
    $this->getJson('/api/v1/app/fleetops/vehicle-profiles/'.Uuid::generate()->value)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'vehicle_profile_not_found');
});

it('lista todos los perfiles técnicos por su endpoint', function (): void {
    VehicleProfileFactory::new()->create();

    $this->getJson('/api/v1/app/fleetops/vehicle-profiles')
        ->assertOk()
        ->assertJsonStructure(['data']);
});
