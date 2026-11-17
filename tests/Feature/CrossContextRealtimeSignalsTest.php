<?php

declare(strict_types=1);

use App\Events\DashboardUpdated;
use App\Events\PickingUpdated;
use App\Events\StoreUpdated;
use App\Jobs\BroadcastDashboardUpdated;
use App\Jobs\BroadcastPickingUpdated;
use App\Jobs\BroadcastStoreUpdated;
use App\Outbox\DashboardRealtimeObserver;
use App\Outbox\PickingRealtimeObserver;
use App\Outbox\StoreRealtimeObserver;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Señales de tiempo real cross-context del host: `picking.updated` (tema picking, ciudad), `store.updated`
// (tema store, nodo) y `dashboard.updated` (tema operations, fecha/ciudad). El observador del outbox se
// ejercita con mensajes armados a mano; el trabajo de difusión se intercepta con Bus::fake (qué se encola) y
// se ejecuta a mano con Event::fake para ver el evento que saldría al socket. Nada habla con un servidor real.

const CCR_AT = '2026-11-15T12:00:00+00:00';

const CCR_CITY = 'santa_cruz';

const CCR_OTHER_CITY = 'la_paz';

const CCR_BROADCAST = 'broadcasting.default';

const CCR_TODAY = '2026-11-15';

beforeEach(function (): void {
    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);
    config([CCR_BROADCAST => 'pusher']);

    Carbon::setTestNow(Carbon::parse(CCR_AT));

    Bus::fake([BroadcastPickingUpdated::class, BroadcastStoreUpdated::class, BroadcastDashboardUpdated::class]);
    Event::fake([PickingUpdated::class, StoreUpdated::class, DashboardUpdated::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

afterEach(function (): void {
    Carbon::setTestNow();
});

function ccrUuid(): string
{
    return Uuid::generate()->value;
}

/**
 * Mensaje del outbox ya procesado, como lo recibe un observador.
 *
 * @param array<string, mixed> $payload
 */
function ccrMessage(string $eventName, array $payload): OutboxMessage
{
    $at = new DateTimeImmutable(CCR_AT);

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'test.aggregate',
        ccrUuid(),
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
 * Siembra un envío con sus departamentos y, opcionalmente, su nodo destino y referencia de pedido.
 */
function ccrShipment(
    string $shipmentId,
    string $destination = CCR_CITY,
    string $origin = CCR_CITY,
    ?string $destinationNode = null,
    ?string $orderReference = null,
): void {
    ShipmentRecord::factory()->create([
        'id' => $shipmentId,
        'origin_department' => $origin,
        'destination_department' => $destination,
        'destination_node_id' => $destinationNode,
        'order_reference' => $orderReference,
    ]);
}

// --- picking.updated --------------------------------------------------------------------------------------

it('un pedido nuevo encola picking.updated con la ciudad del envío y, al correr, lo emite a su tema', function (): void {
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId, orderReference: 'ORD-1');

    app(PickingRealtimeObserver::class)->observe(ccrMessage('shipments.shipment_received', [
        'shipment_id' => $shipmentId,
    ]));

    Bus::assertDispatched(BroadcastPickingUpdated::class, static fn (BroadcastPickingUpdated $job): bool => $job->city === CCR_CITY
        && $job->shipmentId === $shipmentId
        && $job->orderReference === 'ORD-1');

    (new BroadcastPickingUpdated(CCR_CITY, $shipmentId, 'ORD-1', null, null))->handle();

    Event::assertDispatched(PickingUpdated::class, static function (PickingUpdated $event) use ($shipmentId): bool {
        $names = array_map(static fn (PrivateChannel $c): string => $c->name, $event->broadcastOn());

        return $event->broadcastAs() === 'picking.updated'
            && $event->broadcastWith() === ['shipment_id' => $shipmentId, 'order_reference' => 'ORD-1', 'plan_id' => null, 'vehicle_id' => null]
            && $names === ['private-picking.all', 'private-picking.city.'.CCR_CITY]
            && !$event instanceof ShouldBroadcastNow
            && $event instanceof ShouldBroadcast;
    });
});

it('un plan publicado resuelve la ciudad por sus paradas y avisa al tema picking', function (): void {
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId, destination: CCR_OTHER_CITY, origin: CCR_OTHER_CITY);
    $planId = ccrUuid();
    DB::table('planning_plan_stops')->insert([
        'id' => ccrUuid(),
        'plan_id' => $planId,
        'shipment_id' => $shipmentId,
        'sequence' => 1,
        'created_at' => CCR_AT,
        'updated_at' => CCR_AT,
    ]);

    app(PickingRealtimeObserver::class)->observe(ccrMessage('planning.plan_published', [
        'plan_id' => $planId,
        'plan_date' => CCR_TODAY,
        'vehicle_id' => ccrUuid(),
        'stop_count' => 1,
    ]));

    Bus::assertDispatched(BroadcastPickingUpdated::class, static fn (BroadcastPickingUpdated $job): bool => $job->city === CCR_OTHER_CITY
        && $job->planId === $planId
        && $job->shipmentId === null);
});

it('etiquetas emitidas/reimpresas resuelven la ciudad por la referencia del pedido', function (): void {
    ccrShipment(ccrUuid(), orderReference: 'ORD-LBL');

    app(PickingRealtimeObserver::class)->observe(ccrMessage('labels.labels_emitted', [
        'order_number' => 'ORD-LBL',
        'key_id' => 0,
        'label_ids' => ['a'],
        'label_count' => 1,
        'pre_emitted' => false,
        'emitted_at' => 1,
    ]));

    Bus::assertDispatched(BroadcastPickingUpdated::class, static fn (BroadcastPickingUpdated $job): bool => $job->city === CCR_CITY
        && $job->orderReference === 'ORD-LBL');
});

it('una ráfaga de la misma ciudad encola una sola señal de picking (anti-ráfaga)', function (): void {
    $a = ccrUuid();
    $b = ccrUuid();
    ccrShipment($a);
    ccrShipment($b);

    app(PickingRealtimeObserver::class)->observe(ccrMessage('execution.shipment_prepared', ['shipment_id' => $a, 'vehicle_id' => ccrUuid()]));
    app(PickingRealtimeObserver::class)->observe(ccrMessage('execution.shipment_prepared', ['shipment_id' => $b, 'vehicle_id' => ccrUuid()]));

    Bus::assertDispatched(BroadcastPickingUpdated::class, 1);
});

it('picking: un payload sin ids no lanza y encola la señal general (lista en general)', function (): void {
    app(PickingRealtimeObserver::class)->observe(ccrMessage('execution.shipment_prepared', []));

    Bus::assertDispatched(BroadcastPickingUpdated::class, static fn (BroadcastPickingUpdated $job): bool => $job->city === null
        && $job->shipmentId === null);
});

// --- store.updated ----------------------------------------------------------------------------------------

it('el chofer deja el pedido en la tienda y la señal store llega a su nodo', function (): void {
    $nodeId = ccrUuid();
    $shipmentId = ccrUuid();

    app(StoreRealtimeObserver::class)->observe(ccrMessage('execution.shipment_dropped_at_store', [
        'delivery_id' => ccrUuid(),
        'shipment_id' => $shipmentId,
        'node_id' => $nodeId,
        'destination_node_id' => $nodeId,
        'vehicle_id' => ccrUuid(),
        'missing_units' => [],
    ]));

    Bus::assertDispatched(BroadcastStoreUpdated::class, static fn (BroadcastStoreUpdated $job): bool => $job->nodeId === $nodeId
        && $job->shipmentId === $shipmentId);

    (new BroadcastStoreUpdated($nodeId, $shipmentId))->handle();

    Event::assertDispatched(StoreUpdated::class, static function (StoreUpdated $event) use ($nodeId, $shipmentId): bool {
        $names = array_map(static fn (PrivateChannel $c): string => $c->name, $event->broadcastOn());

        return $event->broadcastAs() === 'store.updated'
            && $event->broadcastWith() === ['node_id' => $nodeId, 'shipment_id' => $shipmentId]
            && $names === ['private-store.all', 'private-store.node.'.$nodeId];
    });
});

it('una reprogramación resuelve el nodo destino por el envío', function (): void {
    $nodeId = ccrUuid();
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId, destinationNode: $nodeId);

    app(StoreRealtimeObserver::class)->observe(ccrMessage('shipments.shipment_rescheduled', [
        'shipment_id' => $shipmentId,
        'delivery_date' => CCR_TODAY,
        'window_start' => '09:00',
        'window_end' => '12:00',
        'reason' => null,
    ]));

    Bus::assertDispatched(BroadcastStoreUpdated::class, static fn (BroadcastStoreUpdated $job): bool => $job->nodeId === $nodeId);
});

it('store: sin nodo que resolver no se emite nada', function (): void {
    app(StoreRealtimeObserver::class)->observe(ccrMessage('shipments.shipment_rescheduled', [
        'shipment_id' => ccrUuid(),
        'delivery_date' => CCR_TODAY,
        'window_start' => '09:00',
        'window_end' => '12:00',
    ]));

    Bus::assertNotDispatched(BroadcastStoreUpdated::class);
});

// --- dashboard.updated ------------------------------------------------------------------------------------

it('una entrega registrada avisa al tablero con la fecha del hecho y la ciudad del envío', function (): void {
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId);

    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.shipment_delivered', [
        'delivery_id' => ccrUuid(),
        'shipment_id' => $shipmentId,
        'confirmation' => 'QR',
        'photo_url' => null,
        'occurred_at' => '2026-11-14T18:30:00+00:00',
    ]));

    Bus::assertDispatched(BroadcastDashboardUpdated::class, static fn (BroadcastDashboardUpdated $job): bool => $job->planDate === '2026-11-14'
        && $job->city === CCR_CITY);

    (new BroadcastDashboardUpdated('2026-11-14', CCR_CITY))->handle();

    Event::assertDispatched(DashboardUpdated::class, static function (DashboardUpdated $event): bool {
        $names = array_map(static fn (PrivateChannel $c): string => $c->name, $event->broadcastOn());

        return $event->broadcastAs() === 'dashboard.updated'
            && $event->broadcastWith() === ['plan_date' => '2026-11-14']
            && $names === ['private-operations.all', 'private-operations.city.'.CCR_CITY];
    });
});

it('una entrega fallida sin fecha en el hecho usa el día de hoy', function (): void {
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId);

    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.delivery_failed', [
        'delivery_id' => ccrUuid(),
        'shipment_id' => $shipmentId,
        'reason' => 'absent',
    ]));

    Bus::assertDispatched(BroadcastDashboardUpdated::class, static fn (BroadcastDashboardUpdated $job): bool => $job->planDate === CCR_TODAY);
});

it('un hecho de ruta (sin envío) cae al canal general del tablero', function (): void {
    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.driver_route_started', [
        'route_id' => ccrUuid(),
        'vehicle_id' => ccrUuid(),
        'route_date' => CCR_TODAY,
        'total_stops' => 3,
        'stops_remaining' => 3,
        'odometer_km' => null,
        'lat' => null,
        'lng' => null,
        'occurred_at' => CCR_AT,
    ]));

    Bus::assertDispatched(BroadcastDashboardUpdated::class, static fn (BroadcastDashboardUpdated $job): bool => $job->planDate === CCR_TODAY
        && $job->city === null);
});

it('una ráfaga de la misma fecha y ciudad encola una sola señal de tablero (anti-ráfaga)', function (): void {
    $a = ccrUuid();
    $b = ccrUuid();
    ccrShipment($a);
    ccrShipment($b);

    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.shipment_loaded', ['shipment_id' => $a, 'vehicle_id' => ccrUuid()]));
    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.shipment_loaded', ['shipment_id' => $b, 'vehicle_id' => ccrUuid()]));

    Bus::assertDispatched(BroadcastDashboardUpdated::class, 1);
});

// --- transporte apagado + guardas ------------------------------------------------------------------------

it('sin transporte de tiempo real el observador no encola nada (default seguro)', function (): void {
    config([CCR_BROADCAST => 'null']);
    $shipmentId = ccrUuid();
    ccrShipment($shipmentId);

    app(PickingRealtimeObserver::class)->observe(ccrMessage('shipments.shipment_received', ['shipment_id' => $shipmentId]));
    app(DashboardRealtimeObserver::class)->observe(ccrMessage('execution.delivery_failed', ['shipment_id' => $shipmentId, 'reason' => 'x']));

    Bus::assertNothingDispatched();
});

it('sin transporte de tiempo real el trabajo no emite ningún evento', function (): void {
    config([CCR_BROADCAST => 'null']);

    (new BroadcastPickingUpdated(CCR_CITY, ccrUuid(), null, null, null))->handle();
    (new BroadcastStoreUpdated(ccrUuid(), null))->handle();
    (new BroadcastDashboardUpdated(CCR_TODAY, null))->handle();

    Event::assertNothingDispatched();
});

it('un evento ajeno a los temas no dispara ninguna señal', function (): void {
    app(PickingRealtimeObserver::class)->observe(ccrMessage('sap_connector.something_happened', ['shipment_id' => ccrUuid()]));
    app(StoreRealtimeObserver::class)->observe(ccrMessage('sap_connector.something_happened', ['node_id' => ccrUuid()]));
    app(DashboardRealtimeObserver::class)->observe(ccrMessage('sap_connector.something_happened', ['shipment_id' => ccrUuid()]));

    Bus::assertNothingDispatched();
});
