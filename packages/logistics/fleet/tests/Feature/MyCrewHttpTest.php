<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

it('no le quita la entrega al chofer a la medianoche: ruta EN CURSO de ayer sigue siendo su asignación', function (): void {
    $vehicleId = registrarVehiculoMyCrew();
    $driverId = registrarChoferMyCrew('Chofer Nocturno');
    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('America/La_Paz')))->format('Y-m-d');

    // Cuadrilla de AYER + ruta EN CURSO de ayer (entrega larga que cruzó la medianoche). HOY no hay cuadrilla.
    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: $yesterday,
    ));
    DB::table('execution_driver_routes')->insert([
        'id' => Uuid::generate()->value,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'route_date' => $yesterday,
        'status' => 'IN_PROGRESS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    // Sin ?date (asignación VIGENTE): aunque no hay cuadrilla de HOY, la ruta en curso de ayer sigue siendo suya.
    $this->getJson('/api/v1/app/fleet/my-crew')
        ->assertOk()
        ->assertJsonPath('data.driver.id', $driverId)
        ->assertJsonPath('data.vehicle.id', $vehicleId)
        ->assertJsonPath('data.date', $yesterday);
});

it('la entrega en curso se resuelve por el driver_id de la ruta, no por el camión de una tripulación más nueva', function (): void {
    // Regresión (staging 2026-11): el chofer tiene su entrega EN CURSO en el camión A (ayer), pero una
    // tripulación MÁS NUEVA suya quedó en el camión B (hoy), y el camión B tiene abierta la ruta de OTRO
    // chofer. El barrido por camión agarraba la ruta ajena de B y tapaba/perdía la entrega real del chofer.
    $vehicleA = registrarVehiculoMyCrew();
    $vehicleB = registrarVehiculoMyCrew();
    $driverId = registrarChoferMyCrew('Chofer Reasignado');
    $otherDriverId = registrarChoferMyCrew('Otro Chofer');
    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/La_Paz')))->format('Y-m-d');

    // AYER: tripulación + entrega EN CURSO del chofer en el camión A (su asignación real).
    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleA,
        assembledFor: $yesterday,
    ));
    DB::table('execution_driver_routes')->insert([
        'id' => Uuid::generate()->value,
        'vehicle_id' => $vehicleA,
        'driver_id' => $driverId,
        'route_date' => $yesterday,
        'status' => 'IN_PROGRESS',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    // HOY: tripulación MÁS NUEVA del chofer en el camión B, pero la ruta abierta de B es de OTRO chofer.
    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleB,
        assembledFor: $today,
    ));
    DB::table('execution_driver_routes')->insert([
        'id' => Uuid::generate()->value,
        'vehicle_id' => $vehicleB,
        'driver_id' => $otherDriverId,
        'route_date' => $today,
        'status' => 'IN_PROGRESS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    // Sin ?date: debe devolver SU entrega en curso (camión A, ayer), nunca la ruta ajena del camión B.
    $this->getJson('/api/v1/app/fleet/my-crew')
        ->assertOk()
        ->assertJsonPath('data.driver.id', $driverId)
        ->assertJsonPath('data.vehicle.id', $vehicleA)
        ->assertJsonPath('data.date', $yesterday);
});

it('con ?date explícito NO arrastra la ruta en curso de otro día (previsualización respeta la fecha)', function (): void {
    $vehicleId = registrarVehiculoMyCrew();
    $driverId = registrarChoferMyCrew('Chofer Preview');
    $yesterday = (new DateTimeImmutable('yesterday', new DateTimeZone('America/La_Paz')))->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('America/La_Paz')))->format('Y-m-d');

    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: $yesterday,
    ));
    DB::table('execution_driver_routes')->insert([
        'id' => Uuid::generate()->value,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'route_date' => $yesterday,
        'status' => 'IN_PROGRESS',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    // ?date=mañana EXPLÍCITO: no hay cuadrilla mañana y NO se arrastra la de ayer → 404 (la preview respeta la fecha).
    $this->getJson('/api/v1/app/fleet/my-crew?date='.$tomorrow)
        ->assertNotFound()
        ->assertJsonPath('code', 'not_found');
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

it('devuelve la tripulación al AYUDANTE autenticado (rol tripulante, por users.helper_id)', function (): void {
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

    // El tripulante NO es chofer (driver_id null): se resuelve por su helper_id. La vista es la misma.
    Sanctum::actingAs(User::factory()->create(['driver_id' => null, 'helper_id' => $helperId]));

    $this->getJson('/api/v1/app/fleet/my-crew?date=2026-10-15')
        ->assertOk()
        ->assertJsonPath('data.driver.id', $driverId)
        ->assertJsonPath('data.vehicle.id', $vehicleId)
        ->assertJsonPath('data.helpers.0.id', $helperId)
        ->assertJsonPath('data.helpers.0.name', $helperName)
        ->assertJsonPath('data.date', '2026-10-15');
});

it('devuelve 404 al ayudante que no integra ninguna tripulación esa fecha', function (): void {
    $helperId = registrarAyudanteMyCrew('Ayudante sin tripulación '.uniqid());

    Sanctum::actingAs(User::factory()->create(['driver_id' => null, 'helper_id' => $helperId]));

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
