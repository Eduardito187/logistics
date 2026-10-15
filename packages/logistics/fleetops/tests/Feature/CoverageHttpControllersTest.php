<?php

declare(strict_types=1);

use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los controllers HTTP de la puerta app de Operaciones de flota (Patrón F del brief W2).
 *
 * Se omite el middleware de puerta (auth/device/idempotency) para ejercitar solo el contrato de los
 * controllers, sus FormRequest y la cadena CQRS detrás (comandos, consultas, ReadModels, repositorios y
 * mappers: DepartureMapper, VehicleProfileMapper, MaintenancePlanMapper, EloquentDepartureRepository). No
 * caza bugs: recorre el happy-path real de cada endpoint contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('registra, lista, relee y cierra una salida por sus endpoints', function (): void {
    $vehicleId = Uuid::generate()->value;

    // Registrar la salida (comando RegisterDeparture → repositorio + mapper + outbox).
    $this->postJson('/api/v1/app/fleetops/departures', [
        'vehicle_id' => $vehicleId,
        'driver_id' => Uuid::generate()->value,
        'helper_ids' => [Uuid::generate()->value],
        'destination' => 'El Alto',
        'route_ref' => 'R-01',
        'load' => [
            'description' => 'Línea blanca',
            'weight_kg' => 1200.5,
            'items' => [['name' => 'Heladera', 'qty' => 3]],
        ],
        'odometer_out_km' => 100_000,
        'departed_at' => '2026-10-13T06:00:00+00:00',
        'note' => 'Despacho matinal',
    ])->assertStatus(202)
        ->assertJsonPath('data.accepted', true);

    // Listar la salida (consulta ListDepartures → repositorio listBy + DepartureView).
    $response = $this->getJson("/api/v1/app/fleetops/departures?vehicle_id={$vehicleId}")
        ->assertOk();

    /** @var array<int, array{id: string}> $rows */
    $rows = $response->json('data');
    expect($rows)->not->toBeEmpty();
    $departureId = $rows[0]['id'];

    // Releer el detalle (consulta GetDeparture → findById + mapper toDomain).
    $this->getJson("/api/v1/app/fleetops/departures/{$departureId}")
        ->assertOk()
        ->assertJsonPath('data.id', $departureId);

    // Cerrar la salida al retornar (comando CloseDeparture).
    $this->postJson("/api/v1/app/fleetops/departures/{$departureId}/close", [
        'odometer_in_km' => 100_420,
        'returned_at' => '2026-10-13T18:00:00+00:00',
    ])->assertStatus(202)
        ->assertJsonPath('data.departure_id', $departureId);
});

it('devuelve 404 al releer una salida inexistente', function (): void {
    $this->getJson('/api/v1/app/fleetops/departures/'.Uuid::generate()->value)
        ->assertNotFound();
});

it('define el perfil técnico y fija su odómetro por sus endpoints', function (): void {
    $vehicleId = Uuid::generate()->value;

    // Upsert del perfil técnico (comando DefineVehicleProfile → VehicleProfileMapper).
    $this->postJson('/api/v1/app/fleetops/vehicle-profiles', [
        'vehicle_id' => $vehicleId,
        'brand' => 'Volvo',
        'model' => 'FH',
        'year' => 2022,
        'fuel_type' => 'diesel',
        'tank_liters' => 400.0,
        'vin' => 'VIN-ABC-123',
        'color' => 'Blanco',
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);

    // Fijar el odómetro vigente del perfil (comando UpdateVehicleOdometer).
    $this->postJson("/api/v1/app/fleetops/vehicle-profiles/{$vehicleId}/odometer", [
        'km' => 123_456,
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('registra y lista un gasto de flota por sus endpoints', function (): void {
    $vehicleId = Uuid::generate()->value;

    $this->postJson('/api/v1/app/fleetops/expenses', [
        'vehicle_id' => $vehicleId,
        'category' => 'tires',
        'amount_cents' => 45_000,
        'currency' => 'BOB',
        'incurred_at' => '2026-10-10T09:00:00+00:00',
        'description' => 'Juego de llantas',
        'note' => 'Taller externo',
    ])->assertStatus(202);

    $this->getJson("/api/v1/app/fleetops/expenses?vehicle_id={$vehicleId}&category=tires")
        ->assertOk()
        ->assertJsonPath('data.0.vehicle_id', $vehicleId);
});

it('fija un precio de combustible y registra una carga por sus endpoints', function (): void {
    $vehicleId = Uuid::generate()->value;

    $this->postJson('/api/v1/app/fleetops/fuel-prices', [
        'fuel_type' => 'diesel',
        'price_cents' => 372,
        'currency' => 'BOB',
        'effective_from' => '2026-10-01',
        'source' => 'YPFB',
    ])->assertStatus(202)
        ->assertJsonPath('data.fuel_type', 'diesel');

    $this->postJson('/api/v1/app/fleetops/refuels', [
        'vehicle_id' => $vehicleId,
        'fuel_type' => 'diesel',
        'liters' => 50.0,
        'price_per_liter_cents' => 372,
        'currency' => 'BOB',
        'odometer_km' => 120_000,
        'station' => 'YPFB Central',
        'full_tank' => true,
        'refueled_at' => '2026-10-10T08:00:00+00:00',
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('registra una lectura de odómetro por su endpoint', function (): void {
    $vehicleId = Uuid::generate()->value;

    $this->postJson('/api/v1/app/fleetops/odometer', [
        'vehicle_id' => $vehicleId,
        'km' => 130_000,
        'source' => 'manual',
        'read_at' => '2026-10-11T07:00:00+00:00',
        'note' => 'Lectura de patio',
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('agenda una orden de mantenimiento por su endpoint', function (): void {
    $vehicleId = Uuid::generate()->value;

    $this->postJson('/api/v1/app/fleetops/maintenance/orders', [
        'vehicle_id' => $vehicleId,
        'type' => 'preventive',
        'odometer_km' => 125_000,
        'workshop' => 'Taller Central',
        'scheduled_at' => '2026-10-20T09:00:00+00:00',
        'note' => 'Service de 10.000 km',
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('define un plan de mantenimiento preventivo por su endpoint', function (): void {
    $vehicleId = Uuid::generate()->value;

    // Comando DefineMaintenancePlan → MaintenancePlanMapper (persistencia del plan).
    $this->postJson('/api/v1/app/fleetops/maintenance/plans', [
        'vehicle_id' => $vehicleId,
        'service_kind' => 'oil_change',
        'interval_km' => 10_000,
        'interval_days' => 180,
    ])->assertStatus(202)
        ->assertJsonPath('data.vehicle_id', $vehicleId);
});

it('rechaza con 422 un registro de salida sin vehículo', function (): void {
    $this->postJson('/api/v1/app/fleetops/departures', [
        'departed_at' => '2026-10-13T06:00:00+00:00',
    ])->assertStatus(422);
});
