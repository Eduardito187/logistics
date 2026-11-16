<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logistics\Execution\Infrastructure\Realtime\BroadcastRouteUpdated;
use Logistics\Execution\Infrastructure\Realtime\RouteSignalReader;
use Logistics\Execution\Infrastructure\Realtime\RouteUpdatedObserver;
use Logistics\Execution\Presentation\Broadcasting\RouteUpdated;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Señal de tiempo real de la hoja de ruta del chofer: `route.updated` por el canal privado de cada persona
// de la tripulación (`private-user.{id}`), siempre por la cola y con anti-ráfaga.
//
// El observador del outbox se ejercita con mensajes armados a mano; el trabajo de difusión se intercepta
// con Bus::fake (qué se encola) y se ejecuta a mano con Event::fake para ver el evento que saldría al
// socket. Nada habla con un servidor de sockets real.

/** Día de la ruta en todos los escenarios. */
const RUR_DATE = '2026-11-10';

/** Instante fijo (con desplazamiento explícito) para las filas sembradas y el «hoy» del trabajo. */
const RUR_AT = '2026-11-10T12:00:00+00:00';

const RUR_STOP_COMPLETED = 'execution.route_stop_completed';

const RUR_ROUTES = 'execution_driver_routes';

const RUR_PLANNED = 'PLANNED';

const RUR_COMPLETED = 'COMPLETED';

const RUR_IN_PROGRESS = 'IN_PROGRESS';

/** Día siguiente al de la ruta. */
const RUR_NEXT_DATE = '2026-11-11';

const RUR_BROADCAST_DRIVER = 'broadcasting.default';

const RUR_PUSHER = 'pusher';

beforeEach(function (): void {
    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);

    Carbon::setTestNow(Carbon::parse(RUR_AT));

    Bus::fake([BroadcastRouteUpdated::class]);
    Event::fake([RouteUpdated::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Activa un transporte de sockets (en los tests el default es `null`, que no emite nada).
 */
function rurEnableSockets(): void
{
    config([RUR_BROADCAST_DRIVER => RUR_PUSHER]);
}

function rurUuid(): string
{
    return Uuid::generate()->value;
}

/**
 * Mensaje del outbox ya procesado, como lo recibe un observador.
 *
 * @param array<string, mixed> $payload
 */
function rurMessage(string $eventName, array $payload): OutboxMessage
{
    $at = new DateTimeImmutable(RUR_AT);

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.driver_route',
        rurUuid(),
        $eventName,
        $payload,
        OutboxStatus::Dispatched,
        0,
        $at,
        $at,
        $at,
        null,
    );
}

/**
 * @param array<string, mixed> $payload
 */
function rurObserve(string $eventName, array $payload): void
{
    app(RouteUpdatedObserver::class)->observe(rurMessage($eventName, $payload));
}

/**
 * Siembra una ruta del día y devuelve su id.
 *
 * @param list<string> $helperIds
 */
function rurRoute(string $vehicleId, string $status = RUR_PLANNED, ?string $driverId = null, array $helperIds = [], string $date = RUR_DATE): string
{
    $id = rurUuid();

    DB::table(RUR_ROUTES)->insert([
        'id' => $id,
        'vehicle_id' => $vehicleId,
        'route_date' => $date,
        'status' => $status,
        'driver_id' => $driverId,
        'helper_ids' => json_encode($helperIds),
        'created_at' => RUR_AT,
        'updated_at' => RUR_AT,
    ]);

    return $id;
}

/**
 * Siembra una tripulación del camión para la fecha.
 *
 * @param list<string> $helperIds
 */
function rurCrew(string $vehicleId, string $driverId, array $helperIds = [], string $date = RUR_DATE): void
{
    DB::table('fleet_crews')->insert([
        'id' => rurUuid(),
        'driver_id' => $driverId,
        'helper_ids' => json_encode($helperIds),
        'vehicle_id' => $vehicleId,
        'assembled_for' => $date,
        'created_at' => RUR_AT,
        'updated_at' => RUR_AT,
    ]);
}

function rurStop(string $routeId, string $shipmentId): void
{
    DB::table('execution_driver_route_stops')->insert([
        'id' => rurUuid(),
        'route_id' => $routeId,
        'shipment_id' => $shipmentId,
        'sequence' => 1,
        'status' => 'PENDING',
        'created_at' => RUR_AT,
        'updated_at' => RUR_AT,
    ]);
}

/**
 * Usuario de la app vinculado a un chofer o ayudante de flota; devuelve su id.
 */
function rurUser(?string $driverId): int
{
    return (int) User::factory()->create(['driver_id' => $driverId])->getKey();
}

/**
 * Ejecuta el trabajo de difusión como lo haría el worker.
 */
function rurRun(BroadcastRouteUpdated $job): void
{
    $job->handle(app(RouteSignalReader::class));
}

/**
 * Nombres de canal (con el prefijo `private-`) por los que saldría el evento, ordenados.
 *
 * @return list<string>
 */
function rurChannels(RouteUpdated $event): array
{
    $names = array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

/**
 * Canales esperados para un grupo de usuarios, ordenados.
 *
 * @param  list<int>    $userIds
 * @return list<string>
 */
function rurExpectedChannels(array $userIds): array
{
    $names = array_map(static fn (int $id): string => 'private-user.'.$id, $userIds);
    sort($names);

    return $names;
}

// ──────────────────────────── Contrato del evento ────────────────────────────

it('el evento tiene nombre estable, va por la cola con un solo intento y lleva ruta y camión', function (): void {
    $routeId = rurUuid();
    $vehicleId = rurUuid();
    $event = new RouteUpdated([7, 9], $routeId, $vehicleId);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('route.updated')
        ->and($event->tries)->toBe(1)
        ->and(rurChannels($event))->toBe(['private-user.7', 'private-user.9'])
        ->and($event->broadcastWith())->toBe(['route_id' => $routeId, 'vehicle_id' => $vehicleId])
        ->and((new RouteUpdated([7], null, $vehicleId))->broadcastWith())->toBe(['route_id' => null, 'vehicle_id' => $vehicleId])
        ->and(RouteUpdated::channelName(7))->toBe('user.7')
        ->and(rurChannels(new RouteUpdated(range(1, RouteUpdated::MAX_CHANNELS + 50), $routeId, $vehicleId)))
        ->toHaveCount(RouteUpdated::MAX_CHANNELS);
});

// ──────────────────────────── Observador del outbox ────────────────────────────

it('cada hecho que cambia la hoja de ruta encola la señal, demorada y en la cola de avisos', function (string $eventName): void {
    rurEnableSockets();
    $routeId = rurUuid();
    $vehicleId = rurUuid();
    $shipmentId = rurUuid();

    rurObserve($eventName, ['route_id' => $routeId, 'vehicle_id' => $vehicleId, 'shipment_id' => $shipmentId]);

    Bus::assertDispatchedTimes(BroadcastRouteUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastRouteUpdated::class,
        static fn (BroadcastRouteUpdated $job): bool => $job->routeId === $routeId
            && $job->vehicleIds === [$vehicleId]
            && $job->shipmentId === $shipmentId
            && $job->delay === 2
            && $job->tries === 1
            && $job->queue === config('observability.metrics.queues.notifications', 'notifications'),
    );
})->with(RouteUpdatedObserver::EVENTS);

it('un hecho que no toca la hoja de ruta no encola nada', function (string $eventName): void {
    rurEnableSockets();

    rurObserve($eventName, ['route_id' => rurUuid(), 'vehicle_id' => rurUuid(), 'shipment_id' => rurUuid()]);

    Bus::assertNotDispatched(BroadcastRouteUpdated::class);
})->with(['execution.node_unit_received', 'planning.plan_deleted', 'fleet.driver_registered', 'shipments.shipment_received']);

it('toma del hecho lo que traiga: dos camiones en un cambio de camión, el día en plan y tripulación', function (): void {
    rurEnableSockets();
    $from = rurUuid();
    $to = rurUuid();
    $planned = rurUuid();
    $crewed = rurUuid();
    $shipmentId = rurUuid();

    rurObserve('execution.delivery_vehicle_reassigned', ['shipment_id' => $shipmentId, 'from_vehicle_id' => $from, 'to_vehicle_id' => $to]);
    rurObserve('planning.plan_published', ['plan_id' => rurUuid(), 'plan_date' => RUR_DATE, 'vehicle_id' => $planned]);
    rurObserve('fleet.crew_assembled', ['driver_id' => rurUuid(), 'helper_ids' => [], 'vehicle_id' => $crewed, 'assembled_for' => '2026-11-11T00:00:00+00:00']);
    rurObserve('execution.shipment_delivered', ['delivery_id' => rurUuid(), 'shipment_id' => $shipmentId]);

    Bus::assertDispatched(BroadcastRouteUpdated::class, static fn (BroadcastRouteUpdated $job): bool => $job->vehicleIds === [$from, $to] && $job->shipmentId === $shipmentId);
    Bus::assertDispatched(BroadcastRouteUpdated::class, static fn (BroadcastRouteUpdated $job): bool => $job->vehicleIds === [$planned] && $job->date === RUR_DATE);
    Bus::assertDispatched(BroadcastRouteUpdated::class, static fn (BroadcastRouteUpdated $job): bool => $job->vehicleIds === [$crewed] && $job->date === RUR_NEXT_DATE);
    Bus::assertDispatched(BroadcastRouteUpdated::class, static fn (BroadcastRouteUpdated $job): bool => $job->vehicleIds === [] && $job->routeId === null && $job->shipmentId === $shipmentId);
    Bus::assertDispatchedTimes(BroadcastRouteUpdated::class, 4);
});

it('varios hechos seguidos de la misma ruta se avisan una sola vez; otra ruta tiene su propia ventana', function (): void {
    rurEnableSockets();
    $routeId = rurUuid();
    $vehicleId = rurUuid();

    rurObserve('execution.stop_arrived', ['route_id' => $routeId, 'vehicle_id' => $vehicleId, 'shipment_id' => rurUuid()]);
    rurObserve(RUR_STOP_COMPLETED, ['route_id' => $routeId, 'vehicle_id' => $vehicleId, 'shipment_id' => rurUuid()]);
    rurObserve('execution.route_stop_failed', ['route_id' => $routeId, 'vehicle_id' => $vehicleId, 'shipment_id' => rurUuid()]);

    Bus::assertDispatchedTimes(BroadcastRouteUpdated::class, 1);

    rurObserve(RUR_STOP_COMPLETED, ['route_id' => rurUuid(), 'vehicle_id' => $vehicleId]);

    Bus::assertDispatchedTimes(BroadcastRouteUpdated::class, 2);
});

it('la ventana anti-ráfaga y la demora salen de la config', function (): void {
    rurEnableSockets();
    config(['logistics-execution.realtime.debounce_seconds' => 5]);

    rurObserve(RUR_STOP_COMPLETED, ['route_id' => rurUuid(), 'vehicle_id' => rurUuid()]);

    Bus::assertDispatched(BroadcastRouteUpdated::class, static fn (BroadcastRouteUpdated $job): bool => $job->delay === 5);
});

it('sin transporte de tiempo real el observador no encola', function (?string $driver, bool $enabled): void {
    config([RUR_BROADCAST_DRIVER => $driver, 'logistics-execution.realtime.enabled' => $enabled]);

    rurObserve(RUR_STOP_COMPLETED, ['route_id' => rurUuid(), 'vehicle_id' => rurUuid()]);

    Bus::assertNotDispatched(BroadcastRouteUpdated::class);
})->with([
    'sin transporte' => [null, true],
    'transporte null' => ['null', true],
    'transporte log' => ['log', true],
    'señal apagada' => [RUR_PUSHER, false],
]);

it('el observador nunca lanza ni encola con una carga incompleta o mal formada', function (array $payload): void {
    rurEnableSockets();

    rurObserve(RUR_STOP_COMPLETED, $payload);

    Bus::assertNotDispatched(BroadcastRouteUpdated::class);
})->with([
    'vacía' => [[]],
    'ids que no son UUID' => [['route_id' => 'ruta-1', 'vehicle_id' => 42, 'shipment_id' => 'S-0001']],
    'tipos inesperados' => [['route_id' => ['x'], 'vehicle_id' => null, 'shipment_id' => false, 'route_date' => 20261110]],
]);

it('el observador traga un fallo del caché sin propagarlo', function (): void {
    rurEnableSockets();
    Cache::shouldReceive('add')->once()->andThrow(new RurCacheDownException('caché caído'));

    rurObserve(RUR_STOP_COMPLETED, ['route_id' => rurUuid(), 'vehicle_id' => rurUuid()]);

    Bus::assertNotDispatched(BroadcastRouteUpdated::class);
});

// ──────────────────────────── Trabajo de difusión ────────────────────────────

it('emite route.updated al chofer y a los ayudantes de la tripulación del día, y a nadie más', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $helperId = rurUuid();
    $crewHelperId = rurUuid();
    $routeId = rurRoute($vehicleId, RUR_IN_PROGRESS, $driverId, [$helperId]);
    // La tripulación de flota del mismo día suma a un ayudante que la ruta no tenía grabado.
    rurCrew($vehicleId, $driverId, [$helperId, $crewHelperId]);
    // Tripulación del mismo camión otro día, y de otro camión el mismo día: no son destinatarios.
    $otherDayDriver = rurUuid();
    $otherTruckDriver = rurUuid();
    rurCrew($vehicleId, $otherDayDriver, [], '2026-11-09');
    rurCrew(rurUuid(), $otherTruckDriver);

    $expected = [rurUser($driverId), rurUser($helperId), rurUser($crewHelperId)];
    rurUser($otherDayDriver);
    rurUser($otherTruckDriver);
    rurUser(null);

    rurRun(new BroadcastRouteUpdated($routeId, [$vehicleId]));

    Event::assertDispatchedTimes(RouteUpdated::class, 1);
    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => rurChannels($event) === rurExpectedChannels($expected)
            && $event->broadcastWith() === ['route_id' => $routeId, 'vehicle_id' => $vehicleId],
    );
});

it('sin usuarios vinculados a la tripulación no emite', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $routeId = rurRoute($vehicleId, RUR_PLANNED, rurUuid(), [rurUuid()]);
    rurUser(rurUuid());

    rurRun(new BroadcastRouteUpdated($routeId, [$vehicleId]));
    // Ruta sin tripulación grabada ni en flota.
    rurRun(new BroadcastRouteUpdated(rurRoute(rurUuid())));
    // Hecho sin nada resoluble.
    rurRun(new BroadcastRouteUpdated);

    Event::assertNotDispatched(RouteUpdated::class);
});

it('con solo el camión resuelve la ruta abierta del día antes que la cerrada', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    rurRoute($vehicleId, RUR_COMPLETED);
    $open = rurRoute($vehicleId, RUR_PLANNED);
    rurRoute($vehicleId, RUR_PLANNED, null, [], RUR_NEXT_DATE);
    rurCrew($vehicleId, $driverId);
    $userId = rurUser($driverId);

    // Sin día en el hecho vale el de hoy.
    rurRun(new BroadcastRouteUpdated(null, [$vehicleId], rurUuid()));

    Event::assertDispatchedTimes(RouteUpdated::class, 1);
    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => $event->routeId === $open && $event->userIds === [$userId],
    );
});

it('un camión con tripulación y todavía sin ruta ese día recibe la señal sin ruta', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    rurCrew($vehicleId, $driverId, [], RUR_NEXT_DATE);
    $userId = rurUser($driverId);

    rurRun(new BroadcastRouteUpdated(null, [$vehicleId], null, RUR_NEXT_DATE));

    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => $event->broadcastWith() === ['route_id' => null, 'vehicle_id' => $vehicleId]
            && $event->userIds === [$userId],
    );
});

it('un cambio de camión avisa a las tripulaciones de los dos camiones', function (): void {
    rurEnableSockets();
    $from = rurUuid();
    $to = rurUuid();
    $fromDriver = rurUuid();
    $toDriver = rurUuid();
    $fromRoute = rurRoute($from, RUR_PLANNED, $fromDriver);
    $toRoute = rurRoute($to, RUR_PLANNED, $toDriver);
    $fromUser = rurUser($fromDriver);
    $toUser = rurUser($toDriver);

    rurRun(new BroadcastRouteUpdated(null, [$from, $to], rurUuid()));

    Event::assertDispatchedTimes(RouteUpdated::class, 2);
    Event::assertDispatched(RouteUpdated::class, static fn (RouteUpdated $event): bool => $event->routeId === $fromRoute && $event->userIds === [$fromUser]);
    Event::assertDispatched(RouteUpdated::class, static fn (RouteUpdated $event): bool => $event->routeId === $toRoute && $event->userIds === [$toUser]);
});

it('con solo el envío resuelve la ruta más reciente que lo tiene como parada', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $shipmentId = rurUuid();
    rurStop(rurRoute($vehicleId, RUR_COMPLETED, rurUuid(), [], '2026-11-08'), $shipmentId);
    $current = rurRoute($vehicleId, RUR_IN_PROGRESS, $driverId);
    rurStop($current, $shipmentId);
    $userId = rurUser($driverId);

    rurRun(new BroadcastRouteUpdated(null, [], $shipmentId));

    Event::assertDispatchedTimes(RouteUpdated::class, 1);
    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => $event->routeId === $current && $event->vehicleId === $vehicleId && $event->userIds === [$userId],
    );
});

it('un envío sin parada en ninguna ruta cae al camión de su entrega', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $shipmentId = rurUuid();
    DB::table('execution_deliveries')->insert([
        'id' => rurUuid(),
        'shipment_id' => $shipmentId,
        'vehicle_id' => $vehicleId,
        'status' => 'PREPARING',
        'created_at' => RUR_AT,
        'updated_at' => RUR_AT,
    ]);
    $routeId = rurRoute($vehicleId, RUR_PLANNED, $driverId);
    $userId = rurUser($driverId);

    rurRun(new BroadcastRouteUpdated(null, [], $shipmentId));
    // Envío desconocido: nada que resolver.
    rurRun(new BroadcastRouteUpdated(null, [], rurUuid()));

    Event::assertDispatchedTimes(RouteUpdated::class, 1);
    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => $event->routeId === $routeId && $event->userIds === [$userId],
    );
});

it('una ruta que ya no existe cae al camión del hecho', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $routeId = rurRoute($vehicleId, RUR_PLANNED, $driverId);
    $userId = rurUser($driverId);

    rurRun(new BroadcastRouteUpdated(rurUuid(), [$vehicleId]));

    Event::assertDispatched(
        RouteUpdated::class,
        static fn (RouteUpdated $event): bool => $event->routeId === $routeId && $event->userIds === [$userId],
    );
});

it('dos trabajos de la misma ráfaga que resuelven a la misma ruta emiten una sola señal', function (): void {
    rurEnableSockets();
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $shipmentId = rurUuid();
    $routeId = rurRoute($vehicleId, RUR_IN_PROGRESS, $driverId);
    rurStop($routeId, $shipmentId);
    rurUser($driverId);

    // El mismo cambio llega como hecho del envío y como hecho de su parada.
    rurRun(new BroadcastRouteUpdated(null, [], $shipmentId));
    rurRun(new BroadcastRouteUpdated($routeId, [$vehicleId], $shipmentId));

    Event::assertDispatchedTimes(RouteUpdated::class, 1);
});

it('sin transporte de tiempo real el trabajo no emite', function (): void {
    $vehicleId = rurUuid();
    $driverId = rurUuid();
    $routeId = rurRoute($vehicleId, RUR_PLANNED, $driverId);
    rurUser($driverId);

    rurRun(new BroadcastRouteUpdated($routeId, [$vehicleId]));

    Event::assertNotDispatched(RouteUpdated::class);
});

/**
 * Falla simulada del caché, para comprobar que el observador no la propaga.
 */
final class RurCacheDownException extends RuntimeException {}
