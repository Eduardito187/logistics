<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Ciclo de la ruta del día (#6) y llegada a la parada (#5), contra PostgreSQL:
 * - start → IN_PROGRESS + started_at, idempotente, y la entrega cargada SALE (IN_TRANSIT);
 * - close → COMPLETED aunque queden paradas pendientes (se informan), idempotente;
 * - arrived → arrived_at persistido (la primera gana) + execution.stop_arrived en el outbox.
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
    $this->actingAs(User::factory()->create(), 'sanctum');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const DRLC_DATE = '2026-11-12';
const DRLC_START_AT = '2026-11-12T08:00:00-04:00';

/**
 * Arma una ruta de dos paradas para el vehículo y deja la primera entrega cargada.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function drlcRoute(): array
{
    $vehicle = Uuid::generate()->value;
    $first = Uuid::generate()->value;
    $second = Uuid::generate()->value;

    /** @var CommandBus $bus */
    $bus = app(CommandBus::class);
    $bus->dispatch(new AssembleDailyRoute(
        vehicleId: $vehicle,
        routeDate: DRLC_DATE,
        stops: [
            new RouteStopInput(shipmentId: $first, sequence: 1),
            new RouteStopInput(shipmentId: $second, sequence: 2),
        ],
    ));

    $bus->dispatch(new RecordPreparationScan($first, $vehicle, 'U-1', -16.5, -68.15, 'dev', 'usr', '2026-11-12T07:00:00-04:00', 'drlc-prep-'.$first));
    $bus->dispatch(new RecordLoadScan($first, 'U-1', -16.5, -68.15, 'dev', 'usr', '2026-11-12T07:30:00-04:00', 'drlc-load-'.$first));

    return [$vehicle, $first, $second];
}

it('inicia la ruta, es idempotente y hace salir la entrega cargada', function (): void {
    [$vehicle, $first] = drlcRoute();

    $body = ['occurred_at' => DRLC_START_AT, 'odometer_km' => 1200.5, 'lat' => -16.5, 'lng' => -68.15];

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", $body)
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS')
        ->assertJsonPath('data.start_odometer_km', 1200.5)
        ->assertJsonPath('data.total_stops', 2);

    $startedAt = DB::table('execution_driver_routes')->where('vehicle_id', $vehicle)->value('started_at');
    expect($startedAt)->not->toBeNull();

    // Reintento: misma respuesta, sin un segundo evento de inicio.
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", array_merge($body, ['occurred_at' => '2026-11-12T09:00:00-04:00']))
        ->assertOk()
        ->assertJsonPath('data.status', 'IN_PROGRESS');

    expect(DB::table('outbox_messages')->where('event_name', 'execution.driver_route_started')->count())->toBe(1)
        ->and(DB::table('execution_deliveries')->where('shipment_id', $first)->value('status'))->toBe('IN_TRANSIT');
});

it('cierra la ruta sin exigir todas las paradas y reporta las pendientes', function (): void {
    [$vehicle] = drlcRoute();

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/start", ['occurred_at' => DRLC_START_AT])->assertOk();

    $close = ['occurred_at' => '2026-11-12T18:00:00-04:00', 'odometer_km' => 1290];

    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/close", $close)
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED')
        ->assertJsonPath('data.pending_stops', 2)
        ->assertJsonPath('data.close_odometer_km', 1290.0);

    // Reintento del cierre (la ruta ya no está "abierta"): se ubica por el día de occurred_at, idempotente.
    test()->postJson("/api/v1/app/vehicles/{$vehicle}/route/close", $close)
        ->assertOk()
        ->assertJsonPath('data.status', 'COMPLETED');

    $pending = DB::table('execution_driver_route_stops as s')
        ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
        ->where('r.vehicle_id', $vehicle)
        ->where('s.status', 'PENDING')
        ->count();

    // Las paradas pendientes quedan tal cual (no se fallan ni reprograman al cerrar).
    expect(DB::table('outbox_messages')->where('event_name', 'execution.driver_route_closed')->count())->toBe(1)
        ->and($pending)->toBe(2);
});

it('responde 422 si el vehículo no tiene ruta para iniciar', function (): void {
    test()->postJson('/api/v1/app/vehicles/'.Uuid::generate()->value.'/route/start', ['occurred_at' => DRLC_START_AT])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');
});

it('registra la llegada a la parada: la primera gana y emite execution.stop_arrived', function (): void {
    [$vehicle, $first] = drlcRoute();

    test()->postJson("/api/v1/app/deliveries/{$first}/arrived", [
        'occurred_at' => '2026-11-12T10:00:00-04:00',
        'source' => 'geofence',
        'lat' => -16.51,
        'lng' => -68.16,
    ])
        ->assertOk()
        ->assertJsonPath('data.shipment_id', $first)
        ->assertJsonPath('data.arrival_source', 'geofence');

    $arrivedAt = DB::table('execution_driver_route_stops')->where('shipment_id', $first)->value('arrived_at');
    expect(strtotime((string) $arrivedAt))->toBe(strtotime('2026-11-12T10:00:00-04:00'));

    // Una segunda llegada (manual, más tarde) no pisa la primera.
    test()->postJson("/api/v1/app/deliveries/{$first}/arrived", ['occurred_at' => '2026-11-12T10:05:00-04:00', 'source' => 'manual'])
        ->assertOk()
        ->assertJsonPath('data.arrival_source', 'geofence');

    expect(strtotime((string) DB::table('execution_driver_route_stops')->where('shipment_id', $first)->value('arrived_at')))
        ->toBe(strtotime('2026-11-12T10:00:00-04:00'));
    expect(DB::table('outbox_messages')->where('event_name', 'execution.stop_arrived')->count())->toBe(1);

    // La ruta del chofer expone arrived_at de la parada.
    test()->getJson("/api/v1/app/vehicles/{$vehicle}/route?date=".DRLC_DATE)
        ->assertOk()
        ->assertJsonPath('data.stops.0.arrival_source', 'geofence')
        ->assertJsonPath('data.stops.0.units_total', 0);
});

it('valida el origen de la llegada y rechaza un envío fuera de toda ruta', function (): void {
    [, $first] = drlcRoute();

    test()->postJson("/api/v1/app/deliveries/{$first}/arrived", ['occurred_at' => DRLC_START_AT, 'source' => 'teleport'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    test()->postJson('/api/v1/app/deliveries/'.Uuid::generate()->value.'/arrived', ['occurred_at' => DRLC_START_AT, 'source' => 'manual'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'business_rule_violation');
});
