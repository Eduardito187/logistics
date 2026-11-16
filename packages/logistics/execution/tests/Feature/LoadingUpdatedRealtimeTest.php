<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logistics\Execution\Infrastructure\Realtime\BroadcastLoadingUpdated;
use Logistics\Execution\Infrastructure\Realtime\LoadingSignalReader;
use Logistics\Execution\Infrastructure\Realtime\LoadingUpdatedObserver;
use Logistics\Execution\Presentation\Broadcasting\LoadingUpdated;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;

// Señal de tiempo real de la carga del camión: `loading.updated` por los canales del tema `loading`
// (`loading.all` + ciudad del andén + chofer del camión), siempre por la cola y con anti-ráfaga por vehículo.
//
// El observador del outbox se ejercita con mensajes armados a mano; el trabajo de difusión se intercepta con
// Bus::fake (qué se encola) y se ejecuta a mano con Event::fake para ver el evento que saldría al socket.

/** Instante fijo (con desplazamiento explícito); su día es el «hoy» de la tripulación vigente. */
const LUR_AT = '2026-11-10T12:00:00+00:00';

/** Día de LUR_AT (el de la tripulación vigente). */
const LUR_TODAY = '2026-11-10';

const LUR_BROADCAST_DRIVER = 'broadcasting.default';

const LUR_PUSHER = 'pusher';

const LUR_CITY = 'santa_cruz';

const LUR_LOADED = 'execution.shipment_loaded';

const LUR_UNIT_LOADED = 'execution.unit_loaded';

/** Canal (ya con el prefijo `private-`) de la oficina en el tema `loading`. */
const LUR_CH_ALL = 'private-loading.all';

/** Prefijo del canal de ciudad en el tema `loading` (ya con `private-`). */
const LUR_CH_CITY = 'private-loading.city.';

/** Prefijo del canal de chofer en el tema `loading` (ya con `private-`). */
const LUR_CH_DRIVER = 'private-loading.driver.';

beforeEach(function (): void {
    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);

    Carbon::setTestNow(Carbon::parse(LUR_AT));

    Bus::fake([BroadcastLoadingUpdated::class]);
    Event::fake([LoadingUpdated::class]);
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
function lurEnableSockets(): void
{
    config([LUR_BROADCAST_DRIVER => LUR_PUSHER]);
}

function lurUuid(): string
{
    return Uuid::generate()->value;
}

/**
 * Mensaje del outbox ya procesado, como lo recibe un observador.
 *
 * @param array<string, mixed> $payload
 */
function lurMessage(string $eventName, array $payload): OutboxMessage
{
    $at = new DateTimeImmutable(LUR_AT);

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.delivery',
        lurUuid(),
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
function lurObserve(string $eventName, array $payload): void
{
    app(LoadingUpdatedObserver::class)->observe(lurMessage($eventName, $payload));
}

/**
 * Siembra un nodo de red con su departamento y devuelve su id.
 */
function lurNode(string $department = LUR_CITY): string
{
    $id = lurUuid();

    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'N-'.substr($id, -8),
        'name' => 'Andén',
        'type' => 'WAREHOUSE',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'ACTIVE',
        'department' => $department,
        'created_at' => LUR_AT,
        'updated_at' => LUR_AT,
    ]);

    return $id;
}

/**
 * Siembra un vehículo con su nodo base y devuelve su id.
 */
function lurVehicle(?string $homeBaseNodeId): string
{
    $id = lurUuid();

    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => 'P-'.substr($id, -8),
        'capacity_kg' => 1000,
        'capacity_m3' => 10.0,
        'home_base_node_id' => $homeBaseNodeId ?? lurUuid(),
        'status' => 'ACTIVE',
        'documents' => json_encode([]),
        'created_at' => LUR_AT,
        'updated_at' => LUR_AT,
    ]);

    return $id;
}

/**
 * Siembra una tripulación del camión para la fecha.
 */
function lurCrew(string $vehicleId, string $driverId, string $date = LUR_TODAY): void
{
    DB::table('fleet_crews')->insert([
        'id' => lurUuid(),
        'driver_id' => $driverId,
        'helper_ids' => json_encode([]),
        'vehicle_id' => $vehicleId,
        'assembled_for' => $date,
        'created_at' => LUR_AT,
        'updated_at' => LUR_AT,
    ]);
}

/**
 * Ejecuta el trabajo de difusión como lo haría el worker.
 */
function lurRun(BroadcastLoadingUpdated $job): void
{
    $job->handle(app(LoadingSignalReader::class));
}

/**
 * Nombres de canal (con el prefijo `private-`) por los que saldría el evento, ordenados.
 *
 * @return list<string>
 */
function lurChannels(LoadingUpdated $event): array
{
    $names = array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

// ──────────────────────────── Contrato del evento ────────────────────────────

it('el evento tiene nombre estable, va por la cola con un solo intento y lleva camión y envío', function (): void {
    $vehicleId = lurUuid();
    $shipmentId = lurUuid();
    $event = new LoadingUpdated(['loading.all', 'loading.city.santa_cruz'], $vehicleId, $shipmentId);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('loading.updated')
        ->and($event->tries)->toBe(1)
        ->and(lurChannels($event))->toBe([LUR_CH_ALL, LUR_CH_CITY.LUR_CITY])
        ->and($event->broadcastWith())->toBe(['vehicle_id' => $vehicleId, 'shipment_id' => $shipmentId])
        ->and((new LoadingUpdated(['loading.all'], $vehicleId))->broadcastWith())
        ->toBe(['vehicle_id' => $vehicleId, 'shipment_id' => null]);
});

// ──────────────────────────── Observador del outbox ────────────────────────────

it('cada hecho de carga/lista encola la señal, demorada y en la cola de avisos', function (string $eventName): void {
    lurEnableSockets();
    $vehicleId = lurUuid();
    $shipmentId = lurUuid();

    lurObserve($eventName, ['vehicle_id' => $vehicleId, 'shipment_id' => $shipmentId]);

    Bus::assertDispatchedTimes(BroadcastLoadingUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastLoadingUpdated::class,
        static fn (BroadcastLoadingUpdated $job): bool => $job->vehicleIds === [$vehicleId]
            && $job->shipmentId === $shipmentId
            && $job->delay === 2
            && $job->tries === 1
            && $job->queue === config('observability.metrics.queues.notifications', 'notifications'),
    );
})->with(LoadingUpdatedObserver::EVENTS);

it('la carga de un bulto siguiente (execution.unit_loaded) también encola la señal del andén', function (): void {
    lurEnableSockets();
    $vehicleId = lurUuid();
    $shipmentId = lurUuid();

    lurObserve(LUR_UNIT_LOADED, ['vehicle_id' => $vehicleId, 'shipment_id' => $shipmentId]);

    Bus::assertDispatchedTimes(BroadcastLoadingUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastLoadingUpdated::class,
        static fn (BroadcastLoadingUpdated $job): bool => $job->vehicleIds === [$vehicleId] && $job->shipmentId === $shipmentId,
    );
    expect(LoadingUpdatedObserver::EVENTS)->toContain(LUR_UNIT_LOADED);
});

it('un hecho que no toca la carga no encola nada', function (string $eventName): void {
    lurEnableSockets();

    lurObserve($eventName, ['vehicle_id' => lurUuid(), 'shipment_id' => lurUuid()]);

    Bus::assertNotDispatched(BroadcastLoadingUpdated::class);
})->with(['execution.stop_arrived', 'execution.shipment_delivered', 'planning.plan_published', 'fleet.crew_assembled']);

it('la ruta armada avisa al camión sin envío puntual (lista en general)', function (): void {
    lurEnableSockets();
    $vehicleId = lurUuid();

    lurObserve('execution.driver_route_assembled', ['route_id' => lurUuid(), 'vehicle_id' => $vehicleId, 'route_date' => LUR_TODAY]);

    Bus::assertDispatched(
        BroadcastLoadingUpdated::class,
        static fn (BroadcastLoadingUpdated $job): bool => $job->vehicleIds === [$vehicleId] && $job->shipmentId === null,
    );
});

it('una reasignación avisa a los dos camiones (de y hacia) con el mismo envío', function (): void {
    lurEnableSockets();
    $from = lurUuid();
    $to = lurUuid();
    $shipmentId = lurUuid();

    lurObserve('execution.delivery_vehicle_reassigned', [
        'shipment_id' => $shipmentId,
        'from_vehicle_id' => $from,
        'to_vehicle_id' => $to,
    ]);

    Bus::assertDispatchedTimes(BroadcastLoadingUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastLoadingUpdated::class,
        static fn (BroadcastLoadingUpdated $job): bool => $job->vehicleIds === [$from, $to] && $job->shipmentId === $shipmentId,
    );
});

it('varios hechos seguidos del mismo camión se avisan una sola vez; otro camión tiene su propia ventana', function (): void {
    lurEnableSockets();
    $vehicleId = lurUuid();

    lurObserve(LUR_LOADED, ['vehicle_id' => $vehicleId, 'shipment_id' => lurUuid()]);
    lurObserve('execution.route_stop_attached', ['vehicle_id' => $vehicleId, 'shipment_id' => lurUuid(), 'route_id' => lurUuid()]);

    Bus::assertDispatchedTimes(BroadcastLoadingUpdated::class, 1);

    lurObserve(LUR_LOADED, ['vehicle_id' => lurUuid(), 'shipment_id' => lurUuid()]);

    Bus::assertDispatchedTimes(BroadcastLoadingUpdated::class, 2);
});

it('la ventana anti-ráfaga y la demora salen de la config', function (): void {
    lurEnableSockets();
    config(['logistics-execution.realtime.loading_debounce_seconds' => 5]);

    lurObserve(LUR_LOADED, ['vehicle_id' => lurUuid(), 'shipment_id' => lurUuid()]);

    Bus::assertDispatched(BroadcastLoadingUpdated::class, static fn (BroadcastLoadingUpdated $job): bool => $job->delay === 5);
});

it('sin transporte de tiempo real el observador no encola', function (?string $driver, bool $enabled): void {
    config([LUR_BROADCAST_DRIVER => $driver, 'logistics-execution.realtime.enabled' => $enabled]);

    lurObserve(LUR_LOADED, ['vehicle_id' => lurUuid(), 'shipment_id' => lurUuid()]);

    Bus::assertNotDispatched(BroadcastLoadingUpdated::class);
})->with([
    'sin transporte' => [null, true],
    'transporte null' => ['null', true],
    'transporte log' => ['log', true],
    'señal apagada' => [LUR_PUSHER, false],
]);

it('el observador nunca lanza ni encola con una carga incompleta o mal formada', function (array $payload): void {
    lurEnableSockets();

    lurObserve(LUR_LOADED, $payload);

    Bus::assertNotDispatched(BroadcastLoadingUpdated::class);
})->with([
    'vacía' => [[]],
    'ids que no son UUID' => [['vehicle_id' => 42, 'shipment_id' => 'S-0001']],
    'tipos inesperados' => [['vehicle_id' => ['x'], 'shipment_id' => false]],
]);

it('el observador traga un fallo del caché sin propagarlo', function (): void {
    lurEnableSockets();
    Cache::shouldReceive('add')->andThrow(new LurCacheDownException('caché caído'));

    lurObserve(LUR_LOADED, ['vehicle_id' => lurUuid(), 'shipment_id' => lurUuid()]);

    Bus::assertNotDispatched(BroadcastLoadingUpdated::class);
});

// ──────────────────────────── Trabajo de difusión ────────────────────────────

it('emite loading.updated a la oficina, al andén de la ciudad y al chofer del camión', function (): void {
    lurEnableSockets();
    $driverId = lurUuid();
    $vehicleId = lurVehicle(lurNode());
    lurCrew($vehicleId, $driverId);
    $shipmentId = lurUuid();

    lurRun(new BroadcastLoadingUpdated([$vehicleId], $shipmentId));

    Event::assertDispatchedTimes(LoadingUpdated::class, 1);
    Event::assertDispatched(
        LoadingUpdated::class,
        static fn (LoadingUpdated $event): bool => $event->vehicleId === $vehicleId
            && lurChannels($event) === [LUR_CH_ALL, LUR_CH_CITY.LUR_CITY, LUR_CH_DRIVER.$driverId]
            && $event->broadcastWith() === ['vehicle_id' => $vehicleId, 'shipment_id' => $shipmentId],
    );
});

it('sin tripulación vigente no incluye el canal del chofer, pero sí la oficina y la ciudad', function (): void {
    lurEnableSockets();
    $vehicleId = lurVehicle(lurNode());

    lurRun(new BroadcastLoadingUpdated([$vehicleId]));

    Event::assertDispatched(
        LoadingUpdated::class,
        static fn (LoadingUpdated $event): bool => lurChannels($event) === [LUR_CH_ALL, LUR_CH_CITY.LUR_CITY]
            && $event->broadcastWith() === ['vehicle_id' => $vehicleId, 'shipment_id' => null],
    );
});

it('un camión que no resuelve ni ciudad ni chofer igual recibe la señal por el canal de la oficina', function (): void {
    lurEnableSockets();
    $vehicleId = lurUuid();

    lurRun(new BroadcastLoadingUpdated([$vehicleId]));

    Event::assertDispatched(
        LoadingUpdated::class,
        static fn (LoadingUpdated $event): bool => lurChannels($event) === [LUR_CH_ALL]
            && $event->vehicleId === $vehicleId,
    );
});

it('una reasignación emite una señal por cada camión', function (): void {
    lurEnableSockets();
    $from = lurVehicle(lurNode());
    $to = lurVehicle(lurNode('la_paz'));
    $shipmentId = lurUuid();

    lurRun(new BroadcastLoadingUpdated([$from, $to], $shipmentId));

    Event::assertDispatchedTimes(LoadingUpdated::class, 2);
    Event::assertDispatched(LoadingUpdated::class, static fn (LoadingUpdated $event): bool => $event->vehicleId === $from && in_array(LUR_CH_CITY.LUR_CITY, lurChannels($event), true));
    Event::assertDispatched(LoadingUpdated::class, static fn (LoadingUpdated $event): bool => $event->vehicleId === $to && in_array(LUR_CH_CITY.'la_paz', lurChannels($event), true));
});

it('sin transporte de tiempo real el trabajo no emite', function (): void {
    $vehicleId = lurVehicle(lurNode());

    lurRun(new BroadcastLoadingUpdated([$vehicleId]));

    Event::assertNotDispatched(LoadingUpdated::class);
});

/**
 * Falla simulada del caché, para comprobar que el observador no la propaga.
 */
final class LurCacheDownException extends RuntimeException {}
