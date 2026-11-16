<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * N salidas por día: `GET /api/v1/app/fleet/my-crew` devuelve la tripulación de la SALIDA VIGENTE del
 * chofer (la del camión con ruta abierta; si no hay, la más reciente del día) y su `route_id`. Un chofer
 * puede tener en el día tripulaciones con camiones distintos. Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
    // Estos casos simulan DATOS VIEJOS: varias tripulaciones del mismo chofer en un día (lo que dejó el
    // bug). Hoy el índice único lo impide, pero el lado de lectura sigue tolerándolo; se bajan los índices
    // dentro de la transacción del test (RefreshDatabase la revierte al terminar) para poder insertarlos.
    DB::statement('DROP INDEX IF EXISTS fleet_crews_driver_day_unique');
    DB::statement('DROP INDEX IF EXISTS fleet_crews_vehicle_day_unique');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const MCD_DATE = '2026-11-14';

/**
 * Tripulación del chofer con un camión, con el turno dado (horas locales). Devuelve el id del camión.
 */
function mcdCrew(string $driverId, string $from, string $to, ?string $vehicleId = null): string
{
    $vehicleId ??= (string) Str::uuid7();

    DB::table('fleet_crews')->insert([
        'id' => (string) Str::uuid7(),
        'driver_id' => $driverId,
        'helper_ids' => json_encode([]),
        'vehicle_id' => $vehicleId,
        'assembled_for' => MCD_DATE,
        'shift_starts_at' => MCD_DATE."T{$from}:00-04:00",
        'shift_ends_at' => MCD_DATE."T{$to}:00-04:00",
    ]);

    return $vehicleId;
}

/**
 * Ruta (salida) de un camión en la fecha. Devuelve su id.
 */
function mcdRoute(string $vehicleId, string $status, string $createdAt, ?string $driverId = null): string
{
    $routeId = (string) Str::uuid7();

    DB::table('execution_driver_routes')->insert([
        'id' => $routeId,
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'route_date' => MCD_DATE,
        'status' => $status,
        'created_at' => MCD_DATE." {$createdAt}:00",
        'updated_at' => MCD_DATE." {$createdAt}:00",
    ]);

    return $routeId;
}

function mcdActAsDriver(): string
{
    $driverId = (string) Str::uuid7();
    Sanctum::actingAs(User::factory()->create(['driver_id' => $driverId]));

    return $driverId;
}

it('devuelve la tripulación del camión con ruta abierta, no la primera del día', function (): void {
    $driverId = mcdActAsDriver();
    $truckA = mcdCrew($driverId, '06:00', '10:00');
    $truckB = mcdCrew($driverId, '12:00', '16:00');

    mcdRoute($truckA, 'COMPLETED', '06:00');
    $open = mcdRoute($truckB, 'IN_PROGRESS', '12:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckB)
        ->assertJsonPath('data.route_id', $open)
        ->assertJsonPath('data.route_status', 'IN_PROGRESS');
});

it('la ruta abierta manda aunque exista una tripulación más reciente con otro camión', function (): void {
    $driverId = mcdActAsDriver();
    $truckA = mcdCrew($driverId, '06:00', '10:00');
    mcdCrew($driverId, '12:00', '16:00');

    $open = mcdRoute($truckA, 'PLANNED', '06:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckA)
        ->assertJsonPath('data.route_id', $open)
        ->assertJsonPath('data.route_status', 'PLANNED');
});

it('la ruta en curso manda sobre una planificada de otro camión', function (): void {
    $driverId = mcdActAsDriver();
    $truckA = mcdCrew($driverId, '06:00', '10:00');
    $truckB = mcdCrew($driverId, '12:00', '16:00');

    $running = mcdRoute($truckA, 'IN_PROGRESS', '06:00');
    mcdRoute($truckB, 'PLANNED', '11:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckA)
        ->assertJsonPath('data.route_id', $running);
});

it('sin ruta abierta devuelve la tripulación más reciente del día con la última ruta de su camión', function (): void {
    $driverId = mcdActAsDriver();
    $truckA = mcdCrew($driverId, '06:00', '10:00');
    $truckB = mcdCrew($driverId, '12:00', '16:00');

    mcdRoute($truckA, 'COMPLETED', '06:00');
    mcdRoute($truckB, 'COMPLETED', '12:00');
    $last = mcdRoute($truckB, 'COMPLETED', '14:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckB)
        ->assertJsonPath('data.route_id', $last)
        ->assertJsonPath('data.route_status', 'COMPLETED');
});

it('segunda salida con el MISMO camión: route_id es el de la ruta abierta nueva', function (): void {
    $driverId = mcdActAsDriver();
    $truck = mcdCrew($driverId, '06:00', '16:00');

    mcdRoute($truck, 'COMPLETED', '06:00');
    $second = mcdRoute($truck, 'PLANNED', '12:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truck)
        ->assertJsonPath('data.route_id', $second);
});

it('sin rutas armadas devuelve la tripulación más reciente con route_id null', function (): void {
    $driverId = mcdActAsDriver();
    mcdCrew($driverId, '06:00', '10:00');
    $truckB = mcdCrew($driverId, '12:00', '16:00');

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckB)
        ->assertJsonPath('data.route_id', null)
        ->assertJsonPath('data.route_status', null);
});

it('ignora la ruta abierta del camión cuando la conduce otro chofer', function (): void {
    $driverId = mcdActAsDriver();
    $truckA = mcdCrew($driverId, '06:00', '10:00');
    $truckB = mcdCrew($driverId, '12:00', '16:00');

    // El camión A volvió a salir, pero con OTRO chofer: no es la salida vigente de este.
    mcdRoute($truckA, 'IN_PROGRESS', '11:00', (string) Str::uuid7());

    $this->getJson('/api/v1/app/fleet/my-crew?date='.MCD_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle.id', $truckB)
        ->assertJsonPath('data.route_id', null);
});
