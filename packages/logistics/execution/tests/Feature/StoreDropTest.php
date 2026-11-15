<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Execution\Application\Query\ListDeliveriesByVehicle\ListDeliveriesByVehicle;
use Logistics\Execution\Infrastructure\Outbox\StoreDropOnReceptionReconciled;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Notifications\Infrastructure\Dispatch\ReadyForPickupNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// ENTREGA EN TIENDA del chofer: deja en la tienda un pedido que el cliente retira ahí. No es la entrega al
// cliente: la parada se cierra, el pedido queda «listo para retirar» y se le avisa al cliente una sola vez.
// La entrega al cliente la registra después la tienda con la prueba de entrega de mostrador.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Pedido de cliente con retiro en una tienda, ya cargado en un camión. Devuelve [shipmentId, storeId, vehicleId].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function sdtPickupOnTruck(): array
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

    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'PED-SDT-'.Str::random(6),
        'destination_node_id' => $storeId,
        'source' => 'MAGENTO',
        'status' => 'IN_EXECUTION',
        'customer_email' => 'cliente@dismac.test',
    ]);

    $vehicleId = (string) Str::uuid7();
    DeliveryRecord::factory()->create(['shipment_id' => $shipment->id, 'vehicle_id' => $vehicleId, 'status' => 'LOADED']);

    return [(string) $shipment->id, $storeId, $vehicleId];
}

it('deja el pedido en la tienda sin marcarlo entregado y lo deja listo para retirar', function (): void {
    [$shipmentId, $storeId, $vehicleId] = sdtPickupOnTruck();

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00', 'pod/'.$shipmentId.'/tienda.jpg'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $ready = app(QueryBus::class)->ask(new ListShipments(destinationNodeId: $storeId, readyForPickup: true));

    expect($delivery->status)->toBe('LOADED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and($delivery->getAttribute('store_drop_node_id'))->toBe($storeId)
        ->and(array_map(static fn ($view): string => $view->id, $ready))->toBe([$shipmentId])
        ->and(DB::table('outbox_messages')->where('event_name', 'execution.shipment_dropped_at_store')->where('payload->shipment_id', $shipmentId)->count())->toBe(1)
        // Ya no está en el camión: la pantalla de carga no lo vuelve a ofrecer.
        ->and(app(QueryBus::class)->ask(new ListDeliveriesByVehicle($vehicleId, null, 0, ['LOADED', 'IN_TRANSIT']))->items)->toBe([]);
});

it('repetir la entrega en tienda no vuelve a grabar el hecho', function (): void {
    [$shipmentId, $storeId] = sdtPickupOnTruck();

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));
    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:05:00-04:00', 'pod/foto-tardia.jpg'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect(DB::table('outbox_messages')->where('event_name', 'execution.shipment_dropped_at_store')->where('payload->shipment_id', $shipmentId)->count())->toBe(1)
        // La foto que llegó después se liga igual.
        ->and($delivery->getAttribute('store_drop_photo_path'))->toBe('pod/foto-tardia.jpg');
});

it('el aviso al cliente sale con la entrega del chofer, una sola vez', function (): void {
    [$shipmentId, $storeId] = sdtPickupOnTruck();
    $reactor = app(ReadyForPickupNotificationDispatcher::class);

    $message = static fn (string $event): OutboxMessage => OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.delivery',
        (string) Str::uuid7(),
        $event,
        ['shipment_id' => $shipmentId, 'node_id' => $storeId, 'destination_node_id' => $storeId],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );

    $reactor->dispatch($message('execution.shipment_dropped_at_store'));
    // La conciliación posterior de la tienda no repite el aviso.
    $reactor->dispatch($message('execution.node_reception_reconciled'));

    $rows = DB::table('notifications')->where('shipment_id', $shipmentId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->message_type)->toBe('READY_FOR_PICKUP');
});

it('después la tienda lo entrega en el mostrador y recién ahí queda entregado', function (): void {
    [$shipmentId, $storeId] = sdtPickupOnTruck();
    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));

    app(CommandBus::class)->dispatch(new RecordProofOfDelivery(
        shipmentId: $shipmentId,
        unitId: 'U-1',
        confirmation: 'FIRMA_DIGITAL',
        photoUrl: null,
        photoPath: 'pod/'.$shipmentId.'/cliente.jpg',
        lat: -17.78,
        lng: -63.18,
        deviceId: 'device-tienda',
        userId: 'user-tienda',
        occurredAt: '2026-11-14T16:00:00-04:00',
        idempotencyKey: 'pod-'.$shipmentId,
        counterHandover: true,
    ));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();

    expect($delivery->status)->toBe('DELIVERED')
        ->and($delivery->getAttribute('store_dropped_at'))->not->toBeNull();
});

it('si el chofer no cerró su parada, la conciliación de la tienda registra la entrega en tienda', function (): void {
    [$shipmentId, $storeId] = sdtPickupOnTruck();
    $reactor = app(StoreDropOnReceptionReconciled::class);

    $reconciled = static fn (string $nodeId): OutboxMessage => OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.node_reception',
        (string) Str::uuid7(),
        'execution.node_reception_reconciled',
        ['shipment_id' => $shipmentId, 'destination_node_id' => $nodeId, 'complete' => true],
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );

    // Recepción en un nodo de paso: no es la llegada a su tienda.
    $reactor->dispatch($reconciled((string) Str::uuid7()));
    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->getAttribute('store_dropped_at'))->toBeNull();

    // En su tienda destino: queda registrada una sola vez, aunque el outbox reentregue.
    $reactor->dispatch($reconciled($storeId));
    $reactor->dispatch($reconciled($storeId));

    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->getAttribute('store_drop_node_id'))->toBe($storeId)
        ->and(DB::table('outbox_messages')->where('event_name', 'execution.shipment_dropped_at_store')->where('payload->shipment_id', $shipmentId)->count())->toBe(1);
});
