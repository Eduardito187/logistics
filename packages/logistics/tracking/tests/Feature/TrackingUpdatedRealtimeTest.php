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
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Infrastructure\Realtime\BroadcastTrackingUpdated;
use Logistics\Tracking\Infrastructure\Realtime\TrackingSignalReader;
use Logistics\Tracking\Infrastructure\Realtime\TrackingUpdatedObserver;
use Logistics\Tracking\Presentation\Broadcasting\TrackingUpdated;

// Señal de tiempo real del seguimiento del pedido: `tracking.updated` por el canal de la ficha del pedido
// (`private-order.{shipment_id}`) y por el tema tracking en TODAS las dimensiones de alcance del pedido
// (general, ciudad, nodo, chofer, transportista), siempre por la cola y con anti-ráfaga.
//
// El observador del outbox se ejercita con mensajes armados a mano; el trabajo de difusión se intercepta con
// Bus::fake (qué se encola) y se ejecuta a mano con Event::fake para ver el evento que saldría al socket.
// Nada habla con un servidor de sockets real.

/** Instante fijo (con desplazamiento explícito) para las filas sembradas. */
const TUR_AT = '2026-11-10T12:00:00+00:00';

const TUR_STATUS_CHANGED = 'tracking.status_changed';

const TUR_BROADCAST_DRIVER = 'broadcasting.default';

const TUR_PUSHER = 'pusher';

const TUR_DELIVERED = 'DELIVERED';

const TUR_DEST_CITY = 'santa_cruz';

const TUR_ORIGIN_CITY = 'la_paz';

beforeEach(function (): void {
    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);

    Carbon::setTestNow(Carbon::parse(TUR_AT));

    Bus::fake([BroadcastTrackingUpdated::class]);
    Event::fake([TrackingUpdated::class]);
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
function turEnableSockets(): void
{
    config([TUR_BROADCAST_DRIVER => TUR_PUSHER]);
}

function turUuid(): string
{
    return Uuid::generate()->value;
}

/**
 * Mensaje del outbox ya procesado, como lo recibe un observador.
 *
 * @param array<string, mixed> $payload
 */
function turMessage(string $eventName, array $payload): OutboxMessage
{
    $at = new DateTimeImmutable(TUR_AT);

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'tracking.shipment',
        turUuid(),
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
function turObserve(string $eventName, array $payload): void
{
    app(TrackingUpdatedObserver::class)->observe(turMessage($eventName, $payload));
}

/**
 * Siembra el seguimiento de un envío (read model `tracking_shipments`).
 */
function turTracking(string $shipmentId, string $status = TUR_DELIVERED, ?string $orderReference = null): void
{
    DB::table('tracking_shipments')->insert([
        'id' => turUuid(),
        'shipment_id' => $shipmentId,
        'token' => 'tok-'.turUuid(),
        'status' => $status,
        'remaining_stops' => 0,
        'order_reference' => $orderReference,
        'created_at' => TUR_AT,
        'updated_at' => TUR_AT,
    ]);
}

/**
 * Siembra un envío con sus departamentos de destino y de origen y, opcionalmente, sus nodos (tiendas).
 */
function turShipment(
    string $shipmentId,
    string $destination = TUR_DEST_CITY,
    string $origin = TUR_ORIGIN_CITY,
    ?string $destinationNode = null,
    ?string $originNode = null,
): void {
    ShipmentRecord::factory()->create([
        'id' => $shipmentId,
        'origin_department' => $origin,
        'destination_department' => $destination,
        'destination_node_id' => $destinationNode,
        'origin_node_id' => $originNode,
    ]);
}

/**
 * Siembra una ruta de ejecución con una parada del envío, para resolver el chofer que lo lleva hoy.
 */
function turRoute(string $shipmentId, string $driverId, string $routeDate = '2026-11-10'): void
{
    $routeId = turUuid();

    DB::table('execution_driver_routes')->insert([
        'id' => $routeId,
        'vehicle_id' => turUuid(),
        'driver_id' => $driverId,
        'route_date' => $routeDate,
        'status' => 'IN_PROGRESS',
        'created_at' => TUR_AT,
        'updated_at' => TUR_AT,
    ]);

    DB::table('execution_driver_route_stops')->insert([
        'id' => turUuid(),
        'route_id' => $routeId,
        'shipment_id' => $shipmentId,
        'sequence' => 1,
        'status' => 'PENDING',
        'created_at' => TUR_AT,
        'updated_at' => TUR_AT,
    ]);
}

/**
 * Siembra un viaje de transportista que carga el envío, para resolver el transportista asignado.
 */
function turCarrierTrip(string $shipmentId, string $carrierId): void
{
    $tripId = turUuid();

    DB::table('carrier_trips')->insert([
        'id' => $tripId,
        'carrier_id' => $carrierId,
        'corridor_from' => 'santa_cruz',
        'corridor_to' => 'la_paz',
        'status' => 'IN_PROGRESS',
        'scheduled_at' => TUR_AT,
        'created_at' => TUR_AT,
        'updated_at' => TUR_AT,
    ]);

    DB::table('carrier_trip_shipments')->insert([
        'trip_id' => $tripId,
        'shipment_id' => $shipmentId,
        'position' => 0,
        'created_at' => TUR_AT,
    ]);
}

/**
 * Ejecuta el trabajo de difusión como lo haría el worker.
 */
function turRun(BroadcastTrackingUpdated $job): void
{
    $job->handle(app(TrackingSignalReader::class));
}

/**
 * Nombres de canal (con el prefijo `private-`) por los que saldría el evento, ordenados.
 *
 * @return list<string>
 */
function turChannels(TrackingUpdated $event): array
{
    $names = array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

// ──────────────────────────── Contrato del evento ────────────────────────────

it('el evento tiene nombre estable, va por la cola con un solo intento y lleva el estado del pedido', function (): void {
    $shipmentId = turUuid();
    $event = new TrackingUpdated(
        ['order.'.$shipmentId, 'tracking.all', 'tracking.city.santa_cruz'],
        $shipmentId,
        'ORD-1',
        TUR_DELIVERED,
        TUR_STATUS_CHANGED,
    );

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('tracking.updated')
        ->and($event->tries)->toBe(1)
        ->and($event->timeout)->toBe(15)
        ->and(turChannels($event))->toBe(['private-order.'.$shipmentId, 'private-tracking.all', 'private-tracking.city.santa_cruz'])
        ->and($event->broadcastWith())->toBe([
            'shipment_id' => $shipmentId,
            'order_reference' => 'ORD-1',
            'status' => TUR_DELIVERED,
            'event' => TUR_STATUS_CHANGED,
        ])
        ->and((new TrackingUpdated(['order.'.$shipmentId], $shipmentId, null, 'PROCESSING', TUR_STATUS_CHANGED))->broadcastWith()['order_reference'])->toBeNull()
        ->and(turChannels(new TrackingUpdated(
            array_map(static fn (int $i): string => 'tracking.city.c'.$i, range(1, TrackingUpdated::MAX_CHANNELS + 30)),
            $shipmentId,
            null,
            TUR_DELIVERED,
            TUR_STATUS_CHANGED,
        )))->toHaveCount(TrackingUpdated::MAX_CHANNELS);
});

// ──────────────────────────── Observador del outbox ────────────────────────────

it('cada hecho del seguimiento encola la señal, demorada y en la cola de avisos', function (string $eventName): void {
    turEnableSockets();
    $shipmentId = turUuid();

    turObserve($eventName, ['shipment_id' => $shipmentId, 'status' => TUR_DELIVERED]);

    Bus::assertDispatchedTimes(BroadcastTrackingUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastTrackingUpdated::class,
        static fn (BroadcastTrackingUpdated $job): bool => $job->shipmentId === $shipmentId
            && $job->event === $eventName
            && $job->delay === 2
            && $job->tries === 1
            && $job->queue === config('observability.metrics.queues.notifications', 'notifications'),
    );
})->with(TrackingUpdatedObserver::EVENTS);

it('un cambio a cancelado, reprogramado o devuelto también encola la señal', function (string $status): void {
    turEnableSockets();
    $shipmentId = turUuid();

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => $shipmentId, 'previous_status' => 'ON_THE_WAY', 'status' => $status]);

    Bus::assertDispatchedTimes(BroadcastTrackingUpdated::class, 1);
})->with(['CANCELLED', 'RESCHEDULED', 'RETURNED']);

it('un hecho ajeno al seguimiento no encola nada', function (string $eventName): void {
    turEnableSockets();

    turObserve($eventName, ['shipment_id' => turUuid()]);

    Bus::assertNotDispatched(BroadcastTrackingUpdated::class);
})->with(['execution.shipment_delivered', 'tracking.satisfaction_survey_recorded', 'shipments.shipment_received', 'tracking.opened']);

it('varios hechos seguidos del mismo pedido se avisan una sola vez; otro pedido tiene su propia ventana', function (): void {
    turEnableSockets();
    $shipmentId = turUuid();

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => $shipmentId, 'status' => 'LOADED']);
    turObserve('tracking.unit_status_changed', ['shipment_id' => $shipmentId, 'unit_id' => turUuid(), 'status' => 'LOADED']);
    turObserve('tracking.shipment_placed_on_route', ['shipment_id' => $shipmentId, 'route_id' => turUuid()]);

    Bus::assertDispatchedTimes(BroadcastTrackingUpdated::class, 1);

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => turUuid(), 'status' => 'ON_THE_WAY']);

    Bus::assertDispatchedTimes(BroadcastTrackingUpdated::class, 2);
});

it('la ventana anti-ráfaga y la demora salen de la config', function (): void {
    turEnableSockets();
    config(['logistics-tracking.realtime.debounce_seconds' => 7]);

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => turUuid(), 'status' => TUR_DELIVERED]);

    Bus::assertDispatched(BroadcastTrackingUpdated::class, static fn (BroadcastTrackingUpdated $job): bool => $job->delay === 7);
});

it('sin transporte de tiempo real el observador no encola', function (?string $driver, bool $enabled): void {
    config([TUR_BROADCAST_DRIVER => $driver, 'logistics-tracking.realtime.enabled' => $enabled]);

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => turUuid(), 'status' => TUR_DELIVERED]);

    Bus::assertNotDispatched(BroadcastTrackingUpdated::class);
})->with([
    'sin transporte' => [null, true],
    'transporte null' => ['null', true],
    'transporte log' => ['log', true],
    'señal apagada' => [TUR_PUSHER, false],
]);

it('el observador nunca lanza ni encola con una carga sin envío o mal formada', function (array $payload): void {
    turEnableSockets();

    turObserve(TUR_STATUS_CHANGED, $payload);

    Bus::assertNotDispatched(BroadcastTrackingUpdated::class);
})->with([
    'vacía' => [[]],
    'envío que no es UUID' => [['shipment_id' => 'S-0001', 'status' => TUR_DELIVERED]],
    'tipos inesperados' => [['shipment_id' => 42, 'status' => ['x']]],
    'sin envío ligado todavía' => [['shipment_id' => null, 'status' => 'PROCESSING']],
]);

it('el observador traga un fallo del caché sin propagarlo', function (): void {
    turEnableSockets();
    Cache::shouldReceive('add')->once()->andThrow(new TurCacheDownException('caché caído'));

    turObserve(TUR_STATUS_CHANGED, ['shipment_id' => turUuid(), 'status' => TUR_DELIVERED]);

    Bus::assertNotDispatched(BroadcastTrackingUpdated::class);
});

// ──────────────────────────── Trabajo de difusión ────────────────────────────

it('emite tracking.updated a la ficha del pedido y al tema por ciudad con el estado vigente', function (): void {
    turEnableSockets();
    $shipmentId = turUuid();
    turTracking($shipmentId, TUR_DELIVERED, 'ORD-9');
    turShipment($shipmentId, TUR_DEST_CITY, TUR_ORIGIN_CITY);

    turRun(new BroadcastTrackingUpdated($shipmentId, TUR_STATUS_CHANGED));

    Event::assertDispatchedTimes(TrackingUpdated::class, 1);
    Event::assertDispatched(
        TrackingUpdated::class,
        static fn (TrackingUpdated $event): bool => turChannels($event) === [
            'private-order.'.$shipmentId,
            'private-tracking.all',
            'private-tracking.city.'.TUR_ORIGIN_CITY,
            'private-tracking.city.'.TUR_DEST_CITY,
        ]
            && $event->broadcastWith() === [
                'shipment_id' => $shipmentId,
                'order_reference' => 'ORD-9',
                'status' => TUR_DELIVERED,
                'event' => TUR_STATUS_CHANGED,
            ],
    );
});

it('emite a TODAS las dimensiones del pedido: ciudad, nodo, chofer y transportista, más la ficha y el general', function (): void {
    turEnableSockets();
    $shipmentId = turUuid();
    $destinationNode = turUuid();
    $originNode = turUuid();
    $driverId = turUuid();
    $carrierId = turUuid();
    turTracking($shipmentId, TUR_DELIVERED, 'ORD-DIM');
    turShipment($shipmentId, TUR_DEST_CITY, TUR_ORIGIN_CITY, $destinationNode, $originNode);
    turRoute($shipmentId, $driverId);
    turCarrierTrip($shipmentId, $carrierId);

    turRun(new BroadcastTrackingUpdated($shipmentId, TUR_STATUS_CHANGED));

    $expected = [
        'private-order.'.$shipmentId,
        'private-tracking.all',
        'private-tracking.carrier.'.$carrierId,
        'private-tracking.city.'.TUR_DEST_CITY,
        'private-tracking.city.'.TUR_ORIGIN_CITY,
        'private-tracking.driver.'.$driverId,
        'private-tracking.node.'.$destinationNode,
        'private-tracking.node.'.$originNode,
    ];
    sort($expected);

    Event::assertDispatched(
        TrackingUpdated::class,
        static fn (TrackingUpdated $event): bool => turChannels($event) === $expected,
    );
});

it('un pedido que solo va por transportista (sin ruta de chofer) emite también a su canal de transportista', function (): void {
    turEnableSockets();
    $shipmentId = turUuid();
    $carrierId = turUuid();
    turTracking($shipmentId, 'ON_THE_WAY');
    turShipment($shipmentId, TUR_DEST_CITY, TUR_ORIGIN_CITY);
    turCarrierTrip($shipmentId, $carrierId);

    turRun(new BroadcastTrackingUpdated($shipmentId, TUR_STATUS_CHANGED));

    Event::assertDispatched(
        TrackingUpdated::class,
        static fn (TrackingUpdated $event): bool => in_array('private-tracking.carrier.'.$carrierId, turChannels($event), true)
            && !in_array('private-tracking.driver.'.$carrierId, turChannels($event), true),
    );
});

it('sin el envío en shipments emite solo a la ficha y al canal general del tema', function (): void {
    turEnableSockets();
    $shipmentId = turUuid();
    turTracking($shipmentId, 'ON_THE_WAY');

    turRun(new BroadcastTrackingUpdated($shipmentId, 'tracking.shipment_placed_on_route'));

    Event::assertDispatched(
        TrackingUpdated::class,
        static fn (TrackingUpdated $event): bool => turChannels($event) === ['private-order.'.$shipmentId, 'private-tracking.all']
            && $event->status === 'ON_THE_WAY'
            && $event->orderReference === null,
    );
});

it('un pedido sin seguimiento ya no emite', function (): void {
    turEnableSockets();

    turRun(new BroadcastTrackingUpdated(turUuid(), TUR_STATUS_CHANGED));
    // Un id que no tiene forma de UUID tampoco emite ni hace fallar la consulta.
    turRun(new BroadcastTrackingUpdated('S-0001', TUR_STATUS_CHANGED));

    Event::assertNotDispatched(TrackingUpdated::class);
});

it('sin transporte de tiempo real el trabajo no emite', function (): void {
    $shipmentId = turUuid();
    turTracking($shipmentId);

    turRun(new BroadcastTrackingUpdated($shipmentId, TUR_STATUS_CHANGED));

    Event::assertNotDispatched(TrackingUpdated::class);
});

/**
 * Falla simulada del caché, para comprobar que el observador no la propaga.
 */
final class TurCacheDownException extends RuntimeException {}
