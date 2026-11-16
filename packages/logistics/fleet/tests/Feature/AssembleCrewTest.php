<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Application\Query\ListCrews\ListCrews;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Regla del día: un chofer = un camión por día, un camión = una tripulación por día. Armar para un chofer
 * que ya tiene tripulación ese día la REEMPLAZA de forma atómica (la anterior va a `fleet_crew_history`);
 * repetir la misma asignación es idempotente; si el camión destino ya lo tiene otro chofer → 409 conflict
 * (con placa y nombre, nunca UUID), salvo `replace: true`. Corre contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const ACT_DAY = '2026-11-20';

const ACT_URL = '/api/v1/app/fleet/crews';

const ACT_CREWS = 'fleet_crews';

const ACT_HISTORY = 'fleet_crew_history';

const ACT_DRIVER_COL = 'driver_id';

const ACT_EVENT_CHANGED = 'fleet.crew_vehicle_changed';

/**
 * Registra un vehículo por el bus y devuelve su id (apto para despachar por defecto).
 */
function actVehicle(): string
{
    $plate = strtoupper('AC'.random_int(1000, 9999));

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
function actDriver(string $name = 'Chofer Assemble'): string
{
    $license = 'LIC-AC-'.random_int(100000, 999999);

    app(CommandBus::class)->dispatch(new RegisterDriver(
        name: $name,
        licenseNumber: $license,
        licenseExpiry: '2030-01-01',
    ));

    return (string) DriverModel::query()->where('license_number', $license)->firstOrFail()->getKey();
}

/**
 * Tripulaciones vigentes de un chofer en la fecha de prueba.
 */
function actCrewsOf(string $driverId): Builder
{
    return DB::table(ACT_CREWS)->where(ACT_DRIVER_COL, $driverId)->where('assembled_for', ACT_DAY);
}

it('reasignar a otro camión reemplaza: una vigente, la anterior al historial como replaced y un evento de camión cambiado', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleA = (string) Uuid::generate()->value;
    $vehicleB = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleA, ACT_DAY));
    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleB, ACT_DAY));

    $vigentes = actCrewsOf($driverId)->get();
    $retired = DB::table(ACT_HISTORY)->where(ACT_DRIVER_COL, $driverId)->where('vehicle_id', $vehicleA)->first();

    expect($vigentes)->toHaveCount(1)
        ->and($vigentes->first()?->vehicle_id)->toBe($vehicleB)
        ->and($retired)->not->toBeNull()
        ->and($retired?->reason)->toBe('replaced')
        ->and($retired?->new_vehicle_id)->toBe($vehicleB)
        ->and(DB::table('outbox_messages')->where('event_name', ACT_EVENT_CHANGED)->count())->toBe(1);
});

it('repetir exactamente la misma asignación es idempotente: no duplica ni genera actividad nueva', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleId = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleId, ACT_DAY));
    $crewId = (string) actCrewsOf($driverId)->value('id');
    $eventsAfterFirst = DB::table('outbox_messages')->count();

    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleId, ACT_DAY));

    expect(actCrewsOf($driverId)->count())->toBe(1)
        ->and((string) actCrewsOf($driverId)->value('id'))->toBe($crewId)
        ->and(DB::table(ACT_HISTORY)->where(ACT_DRIVER_COL, $driverId)->count())->toBe(0)
        ->and(DB::table('outbox_messages')->count())->toBe($eventsAfterFirst);
});

it('rechaza con 409 (placa y nombre, sin UUID) cuando el camión lo tiene otro chofer, y replace lo resuelve desplazando al anterior', function (): void {
    $vehicleId = actVehicle();
    $plate = (string) VehicleModel::query()->where('id', $vehicleId)->value('plate');
    $ocupante = actDriver('Rosa Quispe');
    $entrante = actDriver('Luis Mamani');

    app(CommandBus::class)->dispatch(new AssembleCrew($ocupante, [], $vehicleId, ACT_DAY));

    $conflict = $this->postJson(ACT_URL, [
        ACT_DRIVER_COL => $entrante,
        'vehicle_id' => $vehicleId,
        'assembled_for' => ACT_DAY,
    ]);

    $conflict->assertStatus(409)
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('error.code', 'conflict');

    expect($conflict->json('detail'))->toBe($conflict->json('error.message'))
        ->and($conflict->json('detail'))->toContain($plate)
        ->and($conflict->json('detail'))->toContain('Rosa Quispe')
        ->and($conflict->json('detail'))->toContain('20/11/2026')
        ->and($conflict->json('detail'))->not->toContain($vehicleId);

    $this->postJson(ACT_URL, [
        ACT_DRIVER_COL => $entrante,
        'vehicle_id' => $vehicleId,
        'assembled_for' => ACT_DAY,
        'replace' => true,
    ])->assertStatus(201);

    $displaced = DB::table(ACT_HISTORY)->where(ACT_DRIVER_COL, $ocupante)->first();

    expect(actCrewsOf($entrante)->count())->toBe(1)
        ->and(actCrewsOf($ocupante)->count())->toBe(0)
        ->and($displaced)->not->toBeNull()
        ->and($displaced?->reason)->toBe('displaced');
});

it('ayudantes: sin la clave se conservan los anteriores; con una lista, la lista pisa', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleA = (string) Uuid::generate()->value;
    $vehicleB = (string) Uuid::generate()->value;
    $h1 = (string) Uuid::generate()->value;
    $h2 = (string) Uuid::generate()->value;
    $h3 = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverId, [$h1, $h2], $vehicleA, ACT_DAY));
    // Cambia de camión sin opinar de ayudantes (null): se conservan.
    $bus->dispatch(new AssembleCrew($driverId, null, $vehicleB, ACT_DAY));

    $conservados = json_decode((string) actCrewsOf($driverId)->value('helper_ids'), true);
    expect($conservados)->toEqualCanonicalizing([$h1, $h2]);

    // Una lista explícita manda sobre los anteriores.
    $bus->dispatch(new AssembleCrew($driverId, [$h3], $vehicleB, ACT_DAY));

    $pisados = json_decode((string) actCrewsOf($driverId)->value('helper_ids'), true);
    expect($pisados)->toBe([$h3]);
});

it('un ayudante no queda en dos tripulaciones del mismo día: la nueva lo saca de la anterior', function (): void {
    $driverOne = (string) Uuid::generate()->value;
    $driverTwo = (string) Uuid::generate()->value;
    $helperId = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverOne, [$helperId], (string) Uuid::generate()->value, ACT_DAY));
    $bus->dispatch(new AssembleCrew($driverTwo, [$helperId], (string) Uuid::generate()->value, ACT_DAY));

    $one = json_decode((string) actCrewsOf($driverOne)->value('helper_ids'), true);
    $two = json_decode((string) actCrewsOf($driverTwo)->value('helper_ids'), true);

    expect($one)->toBe([])
        ->and($two)->toBe([$helperId]);
});

it('la lectura devuelve la tripulación vigente (el camión nuevo), no la reemplazada', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleA = (string) Uuid::generate()->value;
    $vehicleB = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleA, ACT_DAY));
    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleB, ACT_DAY));

    /** @var array{items: list<array<string, mixed>>, total: int} $page */
    $page = app(QueryBus::class)->ask(new ListCrews(date: ACT_DAY, driverId: $driverId));

    expect($page['total'])->toBe(1)
        ->and($page['items'][0]['vehicle_id'])->toBe($vehicleB);
});

it('expone los resultados created / unchanged / replaced por HTTP', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleA = (string) Uuid::generate()->value;
    $vehicleB = (string) Uuid::generate()->value;

    $created = [ACT_DRIVER_COL => $driverId, 'vehicle_id' => $vehicleA, 'assembled_for' => ACT_DAY];

    $this->postJson(ACT_URL, $created)
        ->assertStatus(201)
        ->assertJsonPath('data.outcome', 'created');

    $this->postJson(ACT_URL, $created)
        ->assertStatus(200)
        ->assertJsonPath('data.outcome', 'unchanged');

    $this->postJson(ACT_URL, [ACT_DRIVER_COL => $driverId, 'vehicle_id' => $vehicleB, 'assembled_for' => ACT_DAY])
        ->assertStatus(200)
        ->assertJsonPath('data.outcome', 'replaced');
});
