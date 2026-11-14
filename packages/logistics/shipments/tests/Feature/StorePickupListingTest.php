<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Notifications\Infrastructure\Dispatch\ReadyForPickupNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Bandeja de recojo en tienda: se busca en el SERVIDOR por código de pedido, nombre o documento, y se puede
// pedir solo lo que ya está en la tienda listo para retirar. Al conciliarse la recepción en la tienda de
// retiro, el cliente recibe UN aviso de que su pedido llegó.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function splStore(): string
{
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => 'Tienda de prueba',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
    ]);

    return $id;
}

/**
 * Deja conciliada la recepción del envío en ese nodo (la mercadería ya está en la tienda).
 */
function splReconcile(string $shipmentId, string $nodeId): void
{
    DB::table('execution_node_receptions')->insert([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'origin_node_id' => (string) Str::uuid7(),
        'destination_node_id' => $nodeId,
        'corridor' => 'cd-tienda',
        'status' => 'RECONCILED',
        'expected_unit_ids' => '[]',
        'opened_at' => '2026-11-14 09:00:00-04',
        'reconciled_at' => '2026-11-14 10:00:00-04',
    ]);
}

/**
 * @return list<string> external_id de los envíos que devuelve el listado con esos filtros
 */
function splList(string $nodeId, ?string $search = null, bool $ready = false): array
{
    $views = app(QueryBus::class)->ask(new ListShipments(destinationNodeId: $nodeId, search: $search, readyForPickup: $ready));
    $ids = array_map(static fn ($view): string => $view->externalId, $views);
    sort($ids);

    return $ids;
}

function splReconciledMessage(string $shipmentId, string $nodeId): OutboxMessage
{
    return OutboxMessage::restore(
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
}

it('busca en el servidor por código de pedido, nombre o documento del cliente', function (): void {
    $store = splStore();
    ShipmentRecord::factory()->create(['external_id' => 'PED-SPL-1001', 'order_reference' => 'PED-SPL-1001', 'destination_node_id' => $store, 'customer_name' => 'Valeria Rojas', 'customer_document_id' => '7788991']);
    ShipmentRecord::factory()->create(['external_id' => 'PED-SPL-2002', 'order_reference' => 'PED-SPL-2002', 'destination_node_id' => $store, 'customer_name' => 'Marcelo Saucedo', 'customer_document_id' => '5544332']);

    expect(splList($store, 'spl-1001'))->toBe(['PED-SPL-1001'])
        ->and(splList($store, 'SAUCEDO'))->toBe(['PED-SPL-2002'])
        ->and(splList($store, '778899'))->toBe(['PED-SPL-1001'])
        ->and(splList($store, 'no-existe'))->toBe([])
        // Los comodines del usuario se buscan como texto literal.
        ->and(splList($store, '%'))->toBe([])
        ->and(splList($store))->toHaveCount(2);
});

it('lista como listos para retirar solo los que ya se recibieron en la tienda y no se entregaron', function (): void {
    $store = splStore();
    $waiting = ShipmentRecord::factory()->create(['external_id' => 'PED-SPL-A', 'destination_node_id' => $store, 'status' => 'IN_EXECUTION']);
    $inTransit = ShipmentRecord::factory()->create(['external_id' => 'PED-SPL-B', 'destination_node_id' => $store, 'status' => 'IN_EXECUTION']);
    $pickedUp = ShipmentRecord::factory()->create(['external_id' => 'PED-SPL-C', 'destination_node_id' => $store, 'status' => 'DELIVERED']);

    splReconcile($waiting->id, $store);
    splReconcile($pickedUp->id, $store);
    // Recibido en OTRO nodo (de paso): todavía no está en su tienda.
    splReconcile($inTransit->id, (string) Str::uuid7());

    expect(splList($store, null, true))->toBe(['PED-SPL-A']);
});

it('avisa al cliente una sola vez cuando su pedido de retiro queda conciliado en su tienda', function (): void {
    $store = splStore();
    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'PED-SPL-AVISO',
        'order_reference' => 'PED-SPL-AVISO',
        'destination_node_id' => $store,
        'source' => 'MAGENTO',
        'customer_email' => 'cliente@dismac.test',
    ]);
    $reactor = app(ReadyForPickupNotificationDispatcher::class);

    // Recepción en un nodo de paso: no avisa.
    $reactor->dispatch(splReconciledMessage($shipment->id, (string) Str::uuid7()));
    expect(DB::table('notifications')->where('shipment_id', $shipment->id)->count())->toBe(0);

    // En su tienda: avisa; un reintento del outbox no repite el aviso.
    $reactor->dispatch(splReconciledMessage($shipment->id, $store));
    $reactor->dispatch(splReconciledMessage($shipment->id, $store));

    $rows = DB::table('notifications')->where('shipment_id', $shipment->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->message_type)->toBe('READY_FOR_PICKUP');
});
