<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Application\Command\ConductPreTripChecklist\ConductPreTripChecklist;
use Logistics\Fleet\Application\Command\DefineChecklistTemplate\DefineChecklistTemplate;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Domain\Exception\RouteAlreadyInspected;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * N salidas por día: la inspección pre-salida va ligada a CADA salida (ruta). Con `route_id` la salida
 * admite una sola inspección válida (la segunda → 409 pre_trip_checklist_already_recorded); otra salida
 * del mismo camión el mismo día se inspecciona de nuevo; una inspección con falla crítica se puede repetir
 * tras la reparación. Un chofer tiene UNA sola tripulación por día: reasignarlo a otro camión reemplaza a
 * la anterior (sus salidas son varias RUTAS del mismo camión, no varias tripulaciones).
 * Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const PTC_ONCE_DAY = '2026-11-14';

const PTC_ONCE_URL = '/api/v1/app/fleet/pre-trip-checklists';

/**
 * @param  array<string, bool>  $items
 * @return array<string, mixed>
 */
function ptcOnceBody(string $vehicleId, string $driverId, ?string $routeId, array $items = ['engine' => true, 'brakes' => true]): array
{
    return [
        'vehicle_id' => $vehicleId,
        'driver_id' => $driverId,
        'items' => $items,
        'performed_on' => PTC_ONCE_DAY,
        'route_id' => $routeId,
    ];
}

it('acepta una inspección por salida y responde 409 en la segunda de la misma ruta', function (): void {
    $vehicleId = (string) VehicleFactory::new()->create()->getKey();
    $driverId = (string) DriverFactory::new()->create()->getKey();
    $routeId = Uuid::generate()->value;

    $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, $routeId))->assertStatus(201);

    $response = $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, $routeId))
        ->assertStatus(409)
        ->assertJsonPath('code', 'pre_trip_checklist_already_recorded')
        ->assertJsonPath('error.code', 'pre_trip_checklist_already_recorded');

    expect($response->json('detail'))->toBe($response->json('error.message'))
        ->and($response->json('detail'))->toContain('inspección pre-salida')
        ->and(DB::table('fleet_pre_trip_checklists')->where('route_id', $routeId)->count())->toBe(1);
});

it('el mismo camión se inspecciona de nuevo en cada salida del día, y sin ruta no hay tope', function (): void {
    $vehicleId = (string) VehicleFactory::new()->create()->getKey();
    $driverId = (string) DriverFactory::new()->create()->getKey();

    $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, Uuid::generate()->value))->assertStatus(201);
    $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, Uuid::generate()->value))->assertStatus(201);
    $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, null))->assertStatus(201);
    $this->postJson(PTC_ONCE_URL, ptcOnceBody($vehicleId, $driverId, null))->assertStatus(201);

    expect(DB::table('fleet_pre_trip_checklists')->where('vehicle_id', $vehicleId)->count())->toBe(4);
});

it('una inspección con falla crítica no ocupa la salida: se puede repetir tras la reparación', function (): void {
    app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
        name: 'Plantilla salida '.uniqid(),
        items: [
            ['key' => 'engine', 'label' => 'Motor', 'category' => 'mechanical', 'criticality' => 'critical'],
            ['key' => 'brakes', 'label' => 'Frenos', 'category' => 'safety', 'criticality' => 'critical'],
        ],
    ));

    $vehicleId = (string) VehicleFactory::new()->create()->getKey();
    $driverId = (string) DriverFactory::new()->create()->getKey();
    $routeId = Uuid::generate()->value;
    $bus = app(CommandBus::class);

    // Frenos fallidos (crítico): la salida queda sin inspección válida.
    $bus->dispatch(new ConductPreTripChecklist($vehicleId, $driverId, ['engine' => true, 'brakes' => false], PTC_ONCE_DAY, null, $routeId));
    // Reparado: la misma salida se vuelve a inspeccionar y ahora sí queda válida.
    $bus->dispatch(new ConductPreTripChecklist($vehicleId, $driverId, ['engine' => true, 'brakes' => true], PTC_ONCE_DAY, null, $routeId));

    expect(fn () => $bus->dispatch(new ConductPreTripChecklist($vehicleId, $driverId, ['engine' => true, 'brakes' => true], PTC_ONCE_DAY, null, $routeId)))
        ->toThrow(RouteAlreadyInspected::class);

    expect(DB::table('fleet_pre_trip_checklists')->where('route_id', $routeId)->count())->toBe(2);
});

it('un chofer tiene una sola tripulación por día: reasignarlo a otro camión reemplaza a la anterior', function (): void {
    $driverId = Uuid::generate()->value;
    $lastVehicle = Uuid::generate()->value;
    $bus = app(CommandBus::class);

    $bus->dispatch(new AssembleCrew($driverId, [], Uuid::generate()->value, PTC_ONCE_DAY, PTC_ONCE_DAY.'T06:00:00+00:00', PTC_ONCE_DAY.'T10:00:00+00:00'));
    $bus->dispatch(new AssembleCrew($driverId, [], $lastVehicle, PTC_ONCE_DAY, PTC_ONCE_DAY.'T12:00:00+00:00', PTC_ONCE_DAY.'T15:00:00+00:00'));

    $crews = DB::table('fleet_crews')->where('driver_id', $driverId)->whereDate('assembled_for', PTC_ONCE_DAY);

    expect($crews->count())->toBe(1)
        ->and($crews->value('vehicle_id'))->toBe($lastVehicle);
});
