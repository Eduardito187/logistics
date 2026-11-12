<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Auditoría #29: GET /app/fleet/my-crew acepta `date` como YYYY-MM-DD y también como fecha-hora ISO 8601
 * (clientes generados que serializan la fecha como date-time); se normaliza a la fecha de calendario
 * escrita por el cliente. Un valor que no es fecha → 422.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Arma una tripulación para el 2026-11-12 y autentica al chofer. Devuelve el id del chofer.
 */
function armarTripulacionFecha(): string
{
    $plate = strtoupper('DF'.random_int(1000, 9999));
    app(CommandBus::class)->dispatch(new RegisterVehicle(
        plate: $plate,
        capacityKg: 3500,
        capacityM3: 18.0,
        homeBaseNodeId: Uuid::generate()->value,
        documents: [],
    ));
    $vehicleId = (string) VehicleModel::query()->where('plate', $plate)->firstOrFail()->getKey();

    $license = 'LIC-DF-'.random_int(100000, 999999);
    app(CommandBus::class)->dispatch(new RegisterDriver(
        name: 'Chofer Fecha',
        licenseNumber: $license,
        licenseExpiry: '2030-01-01',
    ));
    $driverId = (string) DriverModel::query()->where('license_number', $license)->firstOrFail()->getKey();

    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: '2026-11-12',
    ));

    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    return $driverId;
}

it('acepta date como fecha o como fecha-hora ISO 8601 y la normaliza a fecha', function (string $date): void {
    $driverId = armarTripulacionFecha();

    $this->getJson('/api/v1/app/fleet/my-crew?date='.rawurlencode($date))
        ->assertOk()
        ->assertJsonPath('data.driver.id', $driverId)
        ->assertJsonPath('data.date', '2026-11-12');
})->with([
    'fecha' => ['2026-11-12'],
    'fecha-hora UTC con milisegundos' => ['2026-11-12T00:00:00.000Z'],
    'fecha-hora con offset' => ['2026-11-12T08:30:00-04:00'],
    'fecha-hora sin zona' => ['2026-11-12T00:00:00'],
]);

it('rechaza con 422 un date que no es fecha', function (string $date): void {
    armarTripulacionFecha();

    $this->getJson('/api/v1/app/fleet/my-crew?date='.rawurlencode($date))
        ->assertStatus(422);
})->with([
    'texto' => ['mañana'],
    'fecha imposible' => ['2026-02-30'],
    'hora imposible' => ['2026-11-12T25:00:00Z'],
]);
