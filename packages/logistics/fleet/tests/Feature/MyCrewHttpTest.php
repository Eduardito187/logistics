<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelper;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Tests HTTP de la asignación del día del chofer autenticado (GET /api/v1/app/fleet/my-crew).
 *
 * El vínculo cuenta↔chofer es `users.driver_id`. Se omite solo EnsureDeviceToken (el binding de
 * dispositivo no es parte del contrato de este endpoint) y se autentica con Sanctum::actingAs sobre un
 * usuario ligado (o no) a un chofer. Corre contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Registra un vehículo por el bus y devuelve su id (UUID generado por el dominio).
 */
function registrarVehiculoMyCrew(): string
{
    $plate = strtoupper('MC'.random_int(1000, 9999));

    app(CommandBus::class)->dispatch(new RegisterVehicle(
        plate: $plate,
        capacityKg: 3500,
        capacityM3: 18.0,
        homeBaseNodeId: Uuid::generate()->value,
        documents: [],
    ));

    return (string) VehicleModel::query()->where('plate', $plate)->firstOrFail()->getKey();
}

/**
 * Registra un chofer por el bus y devuelve su id.
 */
function registrarChoferMyCrew(string $name = 'Chofer My Crew'): string
{
    $license = 'LIC-MC-'.random_int(100000, 999999);

    app(CommandBus::class)->dispatch(new RegisterDriver(
        name: $name,
        licenseNumber: $license,
        licenseExpiry: '2030-01-01',
    ));

    return (string) DriverModel::query()->where('license_number', $license)->firstOrFail()->getKey();
}

/**
 * Registra un ayudante por el bus y devuelve su id.
 */
function registrarAyudanteMyCrew(string $name): string
{
    app(CommandBus::class)->dispatch(new RegisterHelper(name: $name));

    return (string) HelperModel::query()->where('name', $name)->firstOrFail()->getKey();
}

it('devuelve el vehículo y la tripulación del chofer autenticado para una fecha', function (): void {
    $vehicleId = registrarVehiculoMyCrew();
    $driverId = registrarChoferMyCrew('Juan Pérez');
    $helperName = 'Ayudante '.uniqid();
    $helperId = registrarAyudanteMyCrew($helperName);

    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [$helperId],
        vehicleId: $vehicleId,
        assembledFor: '2026-10-15',
        shiftStartsAt: '2026-10-15 06:00:00',
        shiftEndsAt: '2026-10-15 14:00:00',
    ));

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    $this->getJson('/api/v1/app/fleet/my-crew?date=2026-10-15')
        ->assertOk()
        ->assertJsonPath('data.driver.id', $driverId)
        ->assertJsonPath('data.driver.name', 'Juan Pérez')
        ->assertJsonPath('data.vehicle.id', $vehicleId)
        ->assertJsonPath('data.helpers.0.id', $helperId)
        ->assertJsonPath('data.helpers.0.name', $helperName)
        ->assertJsonPath('data.date', '2026-10-15')
        ->assertJsonStructure(['data' => ['crew_id', 'vehicle' => ['plate'], 'shift' => ['starts_at', 'ends_at']]]);
});

it('devuelve 404 (RFC 7807) cuando el chofer no tiene asignación para la fecha', function (): void {
    $driverId = registrarChoferMyCrew();

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    $this->getJson('/api/v1/app/fleet/my-crew?date=2026-10-15')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('devuelve 404 cuando la cuenta autenticada no está vinculada a un chofer', function (): void {
    Sanctum::actingAs(User::factory()->create(['driver_id' => null]));

    $this->getJson('/api/v1/app/fleet/my-crew?date=2026-10-15')
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
});

it('usa la fecha de hoy (America/La_Paz) cuando no se envía el parámetro date', function (): void {
    $vehicleId = registrarVehiculoMyCrew();
    $driverId = registrarChoferMyCrew();
    $today = (new DateTimeImmutable('now', new DateTimeZone('America/La_Paz')))->format('Y-m-d');

    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: $today,
    ));

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    $this->getJson('/api/v1/app/fleet/my-crew')
        ->assertOk()
        ->assertJsonPath('data.date', $today)
        ->assertJsonPath('data.shift', null);
});
