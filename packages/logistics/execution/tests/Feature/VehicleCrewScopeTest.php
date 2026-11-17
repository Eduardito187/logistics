<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Alcance por VEHÍCULO de la puerta app (middleware `vehicle.crew`): la hoja de ruta, las entregas, el
 * armado / inicio / cierre de la ruta y el reporte de incidente de un camión solo los puede ver y operar
 * la OFICINA (admin/supervisor/planificador) o el TRIPULANTE (chofer o ayudante) de ESE camión el día
 * relevante. La ruta trae PII del cliente (teléfono, dirección, líneas), así que un ajeno recibe 403 sin
 * ver nada de la ruta.
 *
 * Se omiten SOLO los middlewares de puerta ajenos a lo que se prueba (device-token e idempotencia); el
 * middleware bajo prueba (`vehicle.crew`) permanece activo. La autenticación se hace con Sanctum.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const VCS_DATE = '2026-11-12';
const VCS_PREV_DATE = '2026-11-11';
const VCS_FORBIDDEN_CODE = 'out_of_scope_assignment';
const VCS_INCIDENT_BODY = ['severity' => 'MINOR', 'type' => 'BREAKDOWN', 'occurred_at' => '2026-11-12T08:00:00+00:00'];

/**
 * Siembra una tripulación vigente (chofer + un ayudante) de un vehículo para una fecha.
 */
function vcsSeedCrew(string $vehicleId, string $driverId, string $helperId, string $date): void
{
    DB::table('fleet_crews')->insert([
        'id' => Uuid::generate()->value,
        'driver_id' => $driverId,
        'helper_ids' => json_encode([$helperId], JSON_THROW_ON_ERROR),
        'vehicle_id' => $vehicleId,
        'assembled_for' => $date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Arma una ruta de una parada para el vehículo en la fecha, para que las lecturas devuelvan 200.
 */
function vcsAssembleRoute(string $vehicleId, string $date): void
{
    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: $date,
        stops: [new RouteStopInput(shipmentId: Uuid::generate()->value, sequence: 1)],
    ));
}

/**
 * Crea un usuario con el rol indicado, autenticado por Sanctum, con el vínculo de chofer opcional.
 */
function vcsActingAs(Role $role, ?string $driverId): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['driver_id' => $driverId])->save();

    test()->actingAs($user, 'sanctum');

    return $user;
}

it('deja a la oficina leer la ruta y las entregas de cualquier vehículo', function (): void {
    $vehicle = Uuid::generate()->value;
    vcsSeedCrew($vehicle, Uuid::generate()->value, Uuid::generate()->value, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    vcsActingAs(Role::Supervisor, null);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)->assertOk();
    test()->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries")->assertOk();
});

it('deja al chofer tripulante de ese vehículo leer su ruta ese día', function (): void {
    $vehicle = Uuid::generate()->value;
    $driver = Uuid::generate()->value;
    vcsSeedCrew($vehicle, $driver, Uuid::generate()->value, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    vcsActingAs(Role::Chofer, $driver);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicle);
});

it('deja al ayudante tripulante de ese vehículo leer su ruta ese día', function (): void {
    $vehicle = Uuid::generate()->value;
    $helper = Uuid::generate()->value;
    vcsSeedCrew($vehicle, Uuid::generate()->value, $helper, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    // El ayudante se liga a la tripulación por el mismo users.driver_id (único vínculo de la cuenta).
    vcsActingAs(Role::Chofer, $helper);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)->assertOk();
});

it('deja al rol tripulante (ligado por users.helper_id) leer la ruta de su vehículo ese día', function (): void {
    $vehicle = Uuid::generate()->value;
    $helper = Uuid::generate()->value;
    vcsSeedCrew($vehicle, Uuid::generate()->value, $helper, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    // Cuenta tripulante real: NO es chofer (driver_id null); se liga por su users.helper_id. Solo lectura.
    $user = User::factory()->create(['driver_id' => null, 'helper_id' => $helper]);
    $user->assignRole(Role::Tripulante);
    test()->actingAs($user, 'sanctum');

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicle);
});

it('bloquea con 403 al tripulante (por helper_id) que no integra la tripulación de ese vehículo', function (): void {
    $vehicle = Uuid::generate()->value;
    vcsSeedCrew($vehicle, Uuid::generate()->value, Uuid::generate()->value, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    // Ayudante de OTRA tripulación (su helper_id no figura en este camión ese día).
    $user = User::factory()->create(['driver_id' => null, 'helper_id' => Uuid::generate()->value]);
    $user->assignRole(Role::Tripulante);
    test()->actingAs($user, 'sanctum');

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE)
        ->assertJsonMissingPath('data');
});

it('bloquea con 403 al chofer de otro vehículo y no revela nada de la ruta ajena', function (): void {
    $vehicle = Uuid::generate()->value;
    vcsSeedCrew($vehicle, Uuid::generate()->value, Uuid::generate()->value, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    // Chofer con su propio vínculo, pero NO es tripulante de este camión.
    vcsActingAs(Role::Chofer, Uuid::generate()->value);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE)
        ->assertJsonMissingPath('data');

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/deliveries")
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE)
        ->assertJsonMissingPath('data');
});

it('bloquea con 403 al chofer sin tripulación ese día', function (): void {
    $vehicle = Uuid::generate()->value;

    vcsActingAs(Role::Chofer, Uuid::generate()->value);

    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE);
});

it('permite al tripulante iniciar, cerrar y reportar incidente; al ajeno lo bloquea', function (): void {
    $vehicle = Uuid::generate()->value;
    $driver = Uuid::generate()->value;
    vcsSeedCrew($vehicle, $driver, Uuid::generate()->value, VCS_DATE);
    vcsAssembleRoute($vehicle, VCS_DATE);

    vcsActingAs(Role::Chofer, $driver);

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", ['occurred_at' => '2026-11-12T08:00:00+00:00'])
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS');
    // El incidente se reporta con la ruta ABIERTA (la regla de negocio exige ruta en curso).
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/incidents", VCS_INCIDENT_BODY)->assertStatus(202);
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/close", ['occurred_at' => '2026-11-12T18:00:00+00:00'])
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED');

    // Un chofer ajeno queda fuera de las tres escrituras.
    vcsActingAs(Role::Chofer, Uuid::generate()->value);

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", ['occurred_at' => '2026-11-12T08:00:00+00:00'])
        ->assertStatus(403)->assertJsonPath('code', VCS_FORBIDDEN_CODE);
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/close", ['occurred_at' => '2026-11-12T18:00:00+00:00'])
        ->assertStatus(403)->assertJsonPath('code', VCS_FORBIDDEN_CODE);
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/incidents", VCS_INCIDENT_BODY)
        ->assertStatus(403)->assertJsonPath('code', VCS_FORBIDDEN_CODE);
});

it('bloquea con 403 al tripulante de ayer cuando pide la ruta de hoy', function (): void {
    $vehicle = Uuid::generate()->value;
    $driver = Uuid::generate()->value;
    // Tripulante solo el día anterior.
    vcsSeedCrew($vehicle, $driver, Uuid::generate()->value, VCS_PREV_DATE);

    vcsActingAs(Role::Chofer, $driver);

    // Pide la ruta de otro día (en el que no es tripulante): 403.
    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE);
});

it('arrastra la pertenencia por la ruta EN CURSO: el tripulante opera su vehículo sin ?date aunque hoy no tenga tripulación', function (): void {
    $vehicle = Uuid::generate()->value;
    $driver = Uuid::generate()->value;
    // Tripulación + ruta de AYER, puesta EN CURSO (entrega que cruzó la medianoche). HOY no hay tripulación.
    vcsSeedCrew($vehicle, $driver, Uuid::generate()->value, VCS_PREV_DATE);
    vcsAssembleRoute($vehicle, VCS_PREV_DATE);
    DB::table('execution_driver_routes')->where('vehicle_id', $vehicle)->whereDate('route_date', VCS_PREV_DATE)
        ->update(['status' => 'IN_PROGRESS']);

    vcsActingAs(Role::Chofer, $driver);

    // SIN ?date (asignación VIGENTE): la ruta en curso de ayer lo habilita a operar su vehículo hoy.
    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route")
        ->assertOk()
        ->assertJsonPath('data.vehicle_id', $vehicle);

    // Con ?date explícito a un día sin tripulación (previsualizar) NO arrastra: sigue 403.
    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE);
});

it('rechaza con 403 un vehicleId sin forma de UUID sin tocar la base (sin 22P02)', function (): void {
    vcsActingAs(Role::Chofer, Uuid::generate()->value);

    test()->getJson('/api/v1/app/vehicles/not-a-uuid/route?date='.VCS_DATE)
        ->assertStatus(403)
        ->assertJsonPath('code', VCS_FORBIDDEN_CODE);
});
