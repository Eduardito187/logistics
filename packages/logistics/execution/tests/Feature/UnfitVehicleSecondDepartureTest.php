<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * N salidas por día: el bloqueo «camión no apto» (ítem crítico del checklist pre-salida fallido y no
 * liberado) aplica a CADA salida. Un camión que ya salió y volvió puede armar su segunda ruta del día, pero
 * si quedó no apto NO puede iniciarla (422 con el motivo) hasta que un supervisor lo habilite. El reintento
 * del inicio de una ruta que ya salió sigue siendo idempotente. Corre contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    if (!Schema::hasColumn('fleet_vehicles', 'unfit_since')) {
        test()->markTestSkipped('fleet_vehicles.unfit_since todavía no existe (migración de Flota).');
    }

    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    $this->actingAs(User::factory()->create(), 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const USD_UNFIT_DETAIL = 'Vehicle not fit to dispatch (failed critical pre-trip item, not cleared)';

function usdToday(): string
{
    return now('America/La_Paz')->toDateString();
}

function usdVehicle(): string
{
    $id = Uuid::generate()->value;
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => strtoupper(substr(str_replace('-', '', $id), -6)),
        'capacity_kg' => 3000,
        'capacity_m3' => 12,
        'home_base_node_id' => Uuid::generate()->value,
        'status' => 'available',
        'documents' => '[]',
        'unfit_since' => null,
    ]);

    return $id;
}

function usdAssemble(string $vehicleId): void
{
    app(CommandBus::class)->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicleId,
        routeDate: usdToday(),
        stops: [new RouteStopInput(shipmentId: Uuid::generate()->value, sequence: 1)],
    ));
}

function usdMarkUnfit(string $vehicleId, ?string $since): void
{
    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['unfit_since' => $since]);
}

it('no deja iniciar la segunda salida del día con un camión no apto, y sí tras habilitarlo', function (): void {
    $vehicle = usdVehicle();
    $start = "/api/v1/app/vehicles/{$vehicle}/route/start";
    $close = "/api/v1/app/vehicles/{$vehicle}/route/close";

    // Primera salida: sale y vuelve.
    usdAssemble($vehicle);
    test()->postJson($start, ['occurred_at' => usdToday().'T08:00:00-04:00'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    test()->postJson($close, ['occurred_at' => usdToday().'T11:00:00-04:00'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

    // Segunda salida del mismo camión el mismo día: ruta nueva. La inspección falló un ítem crítico.
    usdAssemble($vehicle);
    usdMarkUnfit($vehicle, usdToday());

    test()->postJson($start, ['occurred_at' => usdToday().'T13:00:00-04:00'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation')
        ->assertJsonPath('detail', USD_UNFIT_DETAIL)
        ->assertJsonPath('error.message', USD_UNFIT_DETAIL);

    $routes = DB::table('execution_driver_routes')->where('vehicle_id', $vehicle)->orderBy('created_at')->orderBy('id');
    expect($routes->count())->toBe(2)
        ->and(DB::table('execution_driver_routes')->where('vehicle_id', $vehicle)->where('status', 'PLANNED')->whereNull('started_at')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('event_name', 'execution.driver_route_started')->count())->toBe(1);

    // El supervisor lo habilita tras la reparación: la segunda salida ya puede iniciar.
    usdMarkUnfit($vehicle, null);

    test()->postJson($start, ['occurred_at' => usdToday().'T14:00:00-04:00'])
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS');
});

it('el reintento del inicio de una ruta que ya salió sigue idempotente aunque el camión quede no apto después', function (): void {
    $vehicle = usdVehicle();
    $start = "/api/v1/app/vehicles/{$vehicle}/route/start";

    usdAssemble($vehicle);
    test()->postJson($start, ['occurred_at' => usdToday().'T08:00:00-04:00'])->assertOk();

    usdMarkUnfit($vehicle, usdToday());

    test()->postJson($start, ['occurred_at' => usdToday().'T08:05:00-04:00'])
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS');
});

it('una no-aptitud con fecha futura no frena la salida de hoy', function (): void {
    $vehicle = usdVehicle();
    usdAssemble($vehicle);
    usdMarkUnfit($vehicle, now('America/La_Paz')->addDays(2)->toDateString());

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", ['occurred_at' => usdToday().'T08:00:00-04:00'])
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS');
});
