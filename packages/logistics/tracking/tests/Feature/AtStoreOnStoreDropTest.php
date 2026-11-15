<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\AtStoreOnStoreDrop;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

/**
 * Llegada a la tienda de retiro en el seguimiento del cliente: el reactor del outbox marca `at_store_at`
 * ante `execution.shipment_dropped_at_store` SOLO para un pedido con retiro en tienda en SU tienda destino,
 * una sola vez, y las vistas lo exponen como `ready_for_pickup` con su mensaje. Corre contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Crea una tienda de la red y devuelve su id. */
function asdtStore(): string
{
    $storeId = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $storeId,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => 'Tienda de prueba',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
    ]);

    return $storeId;
}

/**
 * Pedido de cliente con retiro en una tienda, con su seguimiento en el estado dado.
 *
 * @return array{shipment: string, store: string, token: string}
 */
function asdtPickupWithTracking(VisibleStatus $status = VisibleStatus::Loaded): array
{
    $storeId = asdtStore();

    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'PED-ASDT-'.Str::random(6),
        'destination_node_id' => $storeId,
        'source' => 'MAGENTO',
        'status' => 'IN_EXECUTION',
    ]);

    $token = TrackingToken::generate()->toString();
    TrackingRecord::factory()->withStatus($status)->create([
        'shipment_id' => (string) $shipment->id,
        'token' => $token,
        'last_lat' => null,
        'last_lng' => null,
        'last_position_at' => null,
    ]);

    return ['shipment' => (string) $shipment->id, 'store' => $storeId, 'token' => $token];
}

/** Mensaje del outbox de la entrega en tienda del chofer, grabado en `$createdAt`. */
function asdtDropMessage(
    string $shipmentId,
    string $nodeId,
    string $createdAt = '2026-11-14T11:00:00+00:00',
    string $event = 'execution.shipment_dropped_at_store',
): OutboxMessage {
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.delivery',
        (string) Str::uuid7(),
        $event,
        ['shipment_id' => $shipmentId, 'node_id' => $nodeId, 'destination_node_id' => $nodeId],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable($createdAt),
        new DateTimeImmutable($createdAt),
        null,
        null,
    );
}

function asdtRecord(string $shipmentId): TrackingRecord
{
    return TrackingRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
}

it('marks the arrival for a store pickup order dropped at its own store, without changing the status', function (): void {
    ['shipment' => $shipment, 'store' => $store] = asdtPickupWithTracking();

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage($shipment, $store));

    $record = asdtRecord($shipment);

    expect($record->at_store_at?->getTimestamp())->toBe(strtotime('2026-11-14T11:00:00+00:00'))
        ->and($record->status)->toBe(VisibleStatus::Loaded->value);
});

it('does not mark when the node is not the destination store of the order', function (): void {
    ['shipment' => $shipment] = asdtPickupWithTracking();

    // Nodo de paso: el pedido todavía no llegó a SU tienda.
    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage($shipment, asdtStore()));

    expect(asdtRecord($shipment)->at_store_at)->toBeNull();
});

it('does not mark an internal transfer nor an order without tracking', function (): void {
    // Traspaso interno a la tienda: no hay cliente que retire.
    $store = asdtStore();
    $transfer = ShipmentRecord::factory()->transfer()->create(['destination_node_id' => $store]);
    TrackingRecord::factory()->withStatus(VisibleStatus::Loaded)->create(['shipment_id' => (string) $transfer->id]);

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage((string) $transfer->id, $store));

    expect(asdtRecord((string) $transfer->id)->at_store_at)->toBeNull();

    // Retiro en tienda SIN seguimiento: tolerante, no abre uno nuevo.
    $untracked = ShipmentRecord::factory()->create(['destination_node_id' => $store, 'source' => 'MAGENTO']);

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage((string) $untracked->id, $store));

    expect(TrackingRecord::query()->where('shipment_id', (string) $untracked->id)->exists())->toBeFalse();
});

it('ignores other events and incomplete payloads without throwing', function (): void {
    ['shipment' => $shipment, 'store' => $store] = asdtPickupWithTracking();
    $reactor = app(AtStoreOnStoreDrop::class);

    $reactor->dispatch(asdtDropMessage($shipment, $store, event: 'execution.node_reception_reconciled'));
    $reactor->dispatch(asdtDropMessage($shipment, ''));
    // Envío inexistente: la consulta no encuentra nada y el reactor no hace nada.
    $reactor->dispatch(asdtDropMessage(Uuid::generate()->value, $store));

    expect(asdtRecord($shipment)->at_store_at)->toBeNull();
});

it('is idempotent: a redelivery of the fact keeps the first arrival time', function (): void {
    ['shipment' => $shipment, 'store' => $store] = asdtPickupWithTracking();
    $reactor = app(AtStoreOnStoreDrop::class);

    $reactor->dispatch(asdtDropMessage($shipment, $store, '2026-11-14T11:00:00+00:00'));
    $reactor->dispatch(asdtDropMessage($shipment, $store, '2026-11-14T15:00:00+00:00'));

    expect(asdtRecord($shipment)->at_store_at?->getTimestamp())->toBe(strtotime('2026-11-14T11:00:00+00:00'));
});

it('does not mark an order already delivered or cancelled', function (VisibleStatus $status): void {
    ['shipment' => $shipment, 'store' => $store] = asdtPickupWithTracking($status);

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage($shipment, $store));

    expect(asdtRecord($shipment)->at_store_at)->toBeNull();
})->with([
    'delivered' => VisibleStatus::Delivered,
    'cancelled' => VisibleStatus::Cancelled,
    'returned' => VisibleStatus::Returned,
]);

it('tells the customer the order is ready for pickup on the public view, refreshing the cached snapshot', function (): void {
    ['shipment' => $shipment, 'store' => $store, 'token' => $token] = asdtPickupWithTracking();

    // Primera lectura: deja la foto en el caché, todavía sin llegada a la tienda.
    $this->getJson("/api/v1/channels/tracking/{$token}")
        ->assertOk()
        ->assertJsonPath('data.ready_for_pickup', false)
        ->assertJsonPath('data.at_store_at', null)
        ->assertJsonPath('data.stops_message', 'Tu pedido ya está cargado en el camión.');

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage($shipment, $store));

    // El guardado invalidó la foto: la lectura siguiente ya refleja la llegada, con el mismo estado.
    $after = $this->getJson("/api/v1/channels/tracking/{$token}")
        ->assertOk()
        ->assertJsonPath('data.status', VisibleStatus::Loaded->value)
        ->assertJsonPath('data.ready_for_pickup', true)
        ->assertJsonPath('data.stops_message', 'Tu pedido ya está en la tienda. Puedes pasar a recogerlo.');

    expect(strtotime((string) $after->json('data.at_store_at')))->toBe(strtotime('2026-11-14T11:00:00+00:00'));

    $this->get("/t/{$token}")
        ->assertOk()
        ->assertSee('Listo para recoger', false)
        ->assertSee('Tu pedido ya está en la tienda. Puedes pasar a recogerlo.', false);

    // Vista interna (app / back office): mismos campos.
    $this->getJson("/api/v1/app/tracking/shipments/{$shipment}")
        ->assertOk()
        ->assertJsonPath('data.ready_for_pickup', true);
});

it('stops being ready for pickup once the store hands the order over, keeping the arrival time', function (): void {
    ['shipment' => $shipment, 'store' => $store, 'token' => $token] = asdtPickupWithTracking();

    app(AtStoreOnStoreDrop::class)->dispatch(asdtDropMessage($shipment, $store));
    app(CommandBus::class)->dispatch(new ProjectExecutionEvent('execution.shipment_delivered', $shipment));

    $response = $this->getJson("/api/v1/channels/tracking/{$token}")
        ->assertOk()
        ->assertJsonPath('data.status', VisibleStatus::Delivered->value)
        ->assertJsonPath('data.ready_for_pickup', false)
        ->assertJsonPath('data.stops_message', 'Tu pedido fue entregado.');

    expect($response->json('data.at_store_at'))->not->toBeNull();
});
