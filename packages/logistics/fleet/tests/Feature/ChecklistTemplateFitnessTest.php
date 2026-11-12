<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Command\DefineChecklistTemplate\DefineChecklistTemplate;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleFitnessLogModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

const FLEET_FITNESS_TEST_DAY = '2026-11-12';

const FLEET_FITNESS_TEST_CREWS_URL = '/api/v1/app/fleet/crews';

/**
 * Auditoría #27 / #20 / #29: plantilla del checklist servida por el backend, bloqueo de un vehículo NO APTO
 * (falla crítica) para tripulación y salida con 422, habilitación por supervisor, paginación del listado de
 * checklists y `date` de my-crew aceptando fecha o fecha-hora.
 *
 * Se omite el middleware de puerta y se autentica un admin (igual que CoverageWriteControllersTest). Corre
 * contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $admin = User::factory()->create(['name' => 'Supervisor Test']);
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Define (como la más reciente) la plantilla por defecto con frenos y motor CRÍTICOS.
 */
function definirPlantillaPorDefecto(): void
{
    app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
        name: 'Plantilla test '.uniqid(),
        items: [
            ['key' => 'engine', 'label' => 'Motor', 'category' => 'mechanical', 'criticality' => 'critical'],
            ['key' => 'brakes', 'label' => 'Frenos', 'category' => 'safety', 'criticality' => 'critical', 'requires_photo' => true],
            ['key' => 'tires', 'label' => 'Llantas', 'category' => 'tires', 'criticality' => 'major'],
            ['key' => 'fuel', 'label' => 'Combustible', 'category' => 'fluids', 'criticality' => 'minor'],
        ],
    ));
}

function registrarVehiculoFitness(): string
{
    $plate = strtoupper('FT'.random_int(1000, 9999));

    app(CommandBus::class)->dispatch(new RegisterVehicle(
        plate: $plate,
        capacityKg: 3500,
        capacityM3: 18.0,
        homeBaseNodeId: Uuid::generate()->value,
        documents: [],
    ));

    return (string) VehicleModel::query()->where('plate', $plate)->firstOrFail()->getKey();
}

function registrarChoferFitness(): string
{
    $license = 'LIC-FT-'.random_int(100000, 999999);

    app(CommandBus::class)->dispatch(new RegisterDriver(
        name: 'Chofer Fitness',
        licenseNumber: $license,
        licenseExpiry: '2030-01-01',
    ));

    return (string) DriverModel::query()->where('license_number', $license)->firstOrFail()->getKey();
}

it('sirve la plantilla activa con forma estable (criticidad, foto, orden y claves críticas)', function (): void {
    definirPlantillaPorDefecto();

    $this->getJson('/api/v1/app/fleet/checklist-template')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'id', 'name', 'vehicle_type', 'active', 'critical_keys',
            'items' => [['key', 'label', 'category', 'criticality', 'requires_photo', 'position']],
        ]])
        ->assertJsonPath('data.vehicle_type', null)
        ->assertJsonPath('data.items.0.key', 'engine')
        ->assertJsonPath('data.items.0.position', 1)
        ->assertJsonPath('data.items.1.requires_photo', true)
        ->assertJsonPath('data.critical_keys', ['engine', 'brakes']);
});

it('elige la plantilla por tipo de vehículo y cae a la por defecto si no hay una del tipo', function (): void {
    definirPlantillaPorDefecto();
    app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
        name: 'Moto test',
        items: [['key' => 'helmet', 'label' => 'Casco', 'category' => 'safety', 'criticality' => 'critical']],
        vehicleType: 'motorcycle-test',
    ));

    $this->getJson('/api/v1/app/fleet/checklist-template?vehicle_type=motorcycle-test')
        ->assertOk()
        ->assertJsonPath('data.name', 'Moto test')
        ->assertJsonPath('data.critical_keys', ['helmet']);

    $this->getJson('/api/v1/app/fleet/checklist-template?vehicle_type=unknown-type')
        ->assertOk()
        ->assertJsonPath('data.vehicle_type', null);
});

it('rechaza con 422 una plantilla con claves repetidas', function (): void {
    expect(fn () => app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
        name: 'Repetida',
        items: [
            ['key' => 'brakes', 'label' => 'Frenos', 'category' => 'safety', 'criticality' => 'critical'],
            ['key' => 'BRAKES', 'label' => 'Frenos 2', 'category' => 'safety', 'criticality' => 'major'],
        ],
    )))->toThrow(FleetException::class);
});

it('una falla CRÍTICA deja el vehículo no apto: bloquea tripulación y salida (422) hasta que un supervisor lo habilita', function (): void {
    definirPlantillaPorDefecto();
    $vehicleId = registrarVehiculoFitness();
    $driverId = registrarChoferFitness();

    // El chofer reporta frenos fallidos (CRÍTICO) → no apto.
    $this->postJson('/api/v1/app/fleet/pre-trip-checklists', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'items' => ['engine' => true, 'brakes' => false, 'tires' => true, 'fuel' => false],
        'performed_on' => FLEET_FITNESS_TEST_DAY,
    ])->assertStatus(201)
        ->assertJsonPath('data.fit_to_dispatch', false)
        ->assertJsonPath('data.failed_critical', ['brakes'])
        ->assertJsonPath('data.vehicle_fitness.fit_to_dispatch', false)
        ->assertJsonPath('data.vehicle_fitness.unfit_since', FLEET_FITNESS_TEST_DAY);

    $this->getJson("/api/v1/app/fleet/vehicles/{$vehicleId}/fitness?date=2026-11-13")
        ->assertOk()
        ->assertJsonPath('data.fit_to_dispatch', false)
        ->assertJsonPath('data.unfit_items', ['brakes']);

    // Asignación: 422 regla de negocio (ese día y los siguientes).
    $this->postJson(FLEET_FITNESS_TEST_CREWS_URL, [
        'driver_id' => $driverId,
        'vehicle_id' => $vehicleId,
        'assembled_for' => '2026-11-13',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');

    // Salida: 422 regla de negocio.
    $this->postJson('/api/v1/app/fleetops/departures', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'departed_at' => '2026-11-12T08:00:00-04:00',
    ])->assertStatus(422)
        ->assertJsonPath('error.code', 'domain_rule_violation');

    // Eventos al outbox.
    expect(DB::table('outbox_messages')
        ->where('aggregate_id', $vehicleId)
        ->where('event_name', 'fleet.vehicle_marked_unfit')
        ->exists())->toBeTrue();

    // Habilitar sin nota → 422; con nota → apto.
    $this->postJson("/api/v1/app/fleet/vehicles/{$vehicleId}/clear-fitness", [])->assertStatus(422);

    $this->postJson("/api/v1/app/fleet/vehicles/{$vehicleId}/clear-fitness", [
        'note' => 'Se cambiaron las pastillas de freno',
    ])->assertOk()
        ->assertJsonPath('data.fitness.fit_to_dispatch', true);

    expect(DB::table('outbox_messages')
        ->where('aggregate_id', $vehicleId)
        ->where('event_name', 'fleet.vehicle_cleared')
        ->exists())->toBeTrue()
        ->and(VehicleFitnessLogModel::query()->where('vehicle_id', $vehicleId)->pluck('action')->sort()->values()->all())
        ->toBe(['cleared', 'marked_unfit'])
        ->and(VehicleFitnessLogModel::query()->where('vehicle_id', $vehicleId)->where('action', 'cleared')->value('actor_label'))
        ->toBe('Supervisor Test');

    // Restaurado: se arma la tripulación y se registra la salida.
    $this->postJson(FLEET_FITNESS_TEST_CREWS_URL, [
        'driver_id' => $driverId,
        'vehicle_id' => $vehicleId,
        'assembled_for' => '2026-11-13',
    ])->assertStatus(201);

    $this->postJson('/api/v1/app/fleetops/departures', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'departed_at' => '2026-11-13T08:00:00-04:00',
    ])->assertStatus(202);

    // Habilitar un vehículo ya apto → 422.
    $this->postJson("/api/v1/app/fleet/vehicles/{$vehicleId}/clear-fitness", ['note' => 'Nada que hacer'])
        ->assertStatus(422);
});

it('una falla NO crítica no bloquea al vehículo', function (): void {
    definirPlantillaPorDefecto();
    $vehicleId = registrarVehiculoFitness();
    $driverId = registrarChoferFitness();

    $this->postJson('/api/v1/app/fleet/pre-trip-checklists', [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'items' => ['engine' => true, 'brakes' => true, 'tires' => false],
        'performed_on' => FLEET_FITNESS_TEST_DAY,
    ])->assertStatus(201)
        ->assertJsonPath('data.fit_to_dispatch', true)
        ->assertJsonPath('data.failed_critical', [])
        ->assertJsonPath('data.missing_critical', []);

    $this->postJson(FLEET_FITNESS_TEST_CREWS_URL, [
        'driver_id' => $driverId,
        'vehicle_id' => $vehicleId,
        'assembled_for' => FLEET_FITNESS_TEST_DAY,
    ])->assertStatus(201);
});

it('pagina el listado de checklists con el meta estándar, más recientes primero y filtro por fechas', function (): void {
    $vehicleId = registrarVehiculoFitness();
    $driverId = registrarChoferFitness();

    foreach (['2026-11-10', '2026-11-11', FLEET_FITNESS_TEST_DAY] as $day) {
        app(CommandBus::class)->dispatch(new ConductPreTripChecklist(
            vehicleId: $vehicleId,
            driverId: $driverId,
            items: ['engine' => true, 'brakes' => true],
            performedOn: $day,
        ));
    }

    $this->getJson("/api/v1/app/fleet/pre-trip-checklists?vehicle_id={$vehicleId}&per_page=2&page=1")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.performed_on', FLEET_FITNESS_TEST_DAY)
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2)
        ->assertJsonPath('meta.next_page', 2);

    $this->getJson("/api/v1/app/fleet/pre-trip-checklists?vehicle_id={$vehicleId}&per_page=2&page=2")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.next_page', null);

    $this->getJson("/api/v1/app/fleet/pre-trip-checklists?vehicle_id={$vehicleId}&from=2026-11-11&to=2026-11-11")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.count', 1);

    $this->getJson("/api/v1/app/fleet/pre-trip-checklists/latest?vehicle_id={$vehicleId}")
        ->assertOk()
        ->assertJsonPath('data.performed_on', FLEET_FITNESS_TEST_DAY);

    $this->getJson('/api/v1/app/fleet/pre-trip-checklists/latest?vehicle_id='.Uuid::generate()->value)
        ->assertNotFound();
});
