<?php

declare(strict_types=1);

use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Subscriber\ReceiveStockOnGoodsReceived;
use Logistics\Inventory\Infrastructure\Dispatch\GoodsReceivedStockDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Adaptador de outbox inbound.goods_received → ReceiveStock. Aplana la cabecera (almacén + propiedad)
 * sobre cada línea aceptada y delega en el subscriber. PHP puro: un CommandBus que captura todos los
 * comandos despachados, sin tocar la base. Cubre el happy-path, el filtrado de líneas y los early-return.
 */

/**
 * CommandBus de prueba que acumula TODOS los comandos despachados.
 *
 * @return CommandBus&object{commands: list<Command>}
 */
function inventoryCollectingBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $commands = [];

        public function dispatch(Command $command): void
        {
            $this->commands[] = $command;
        }
    };
}

/**
 * Construye un mensaje de outbox Pending con el nombre de evento y el payload dados.
 *
 * @param array<string, mixed> $payload
 */
function inventoryOutboxMessage(string $eventName, array $payload): OutboxMessage
{
    $now = new DateTimeImmutable('2026-10-16T10:00:00+00:00');

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'inbound.asn',
        Uuid::generate()->value,
        $eventName,
        $payload,
        OutboxStatus::Pending,
        0,
        $now,
        $now,
        null,
        null,
    );
}

it('aplana la cabecera sobre cada línea aceptada y despacha un ReceiveStock por línea', function (): void {
    $bus = inventoryCollectingBus();
    $dispatcher = new GoodsReceivedStockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryOutboxMessage('inbound.goods_received', [
        'warehouse_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000c1',
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => '0192aaaa-bbbb-7ccc-8ddd-000000000001',
        'seller_sku_ref' => 'SELLER-REF-9',
        'asn_id' => 'ASN-0001',
        'lines' => [
            ['sku' => 'BEL-TV-55', 'accepted' => 40],
            ['sku' => 'BEL-FRIDGE-10', 'accepted' => 12],
        ],
    ]));

    expect($bus->commands)->toHaveCount(2);

    $first = $bus->commands[0];
    assert($first instanceof ReceiveStock);
    expect($first->warehouseId)->toBe('0192aaaa-bbbb-7ccc-8ddd-0000000000c1')
        ->and($first->sku)->toBe('BEL-TV-55')
        ->and($first->quantity)->toBe(40)
        ->and($first->fulfillmentMode)->toBe('fbd')
        ->and($first->ownerPartyId)->toBe('0192aaaa-bbbb-7ccc-8ddd-000000000001')
        ->and($first->sellerSkuRef)->toBe('SELLER-REF-9')
        ->and($first->goodsReceiptRef)->toBe('ASN-0001');

    $second = $bus->commands[1];
    assert($second instanceof ReceiveStock);
    expect($second->sku)->toBe('BEL-FRIDGE-10')
        ->and($second->quantity)->toBe(12);
});

it('aplica owned por defecto cuando la cabecera no trae modo de fulfillment', function (): void {
    $bus = inventoryCollectingBus();
    $dispatcher = new GoodsReceivedStockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryOutboxMessage('inbound.goods_received', [
        'warehouse_id' => 'wh-1',
        'lines' => [['sku' => 'DIS-1', 'accepted' => 5]],
    ]));

    $command = $bus->commands[0];
    assert($command instanceof ReceiveStock);
    expect($command->fulfillmentMode)->toBe('owned')
        ->and($command->ownerPartyId)->toBeNull();
});

it('ignora líneas sin cantidad positiva, sin sku o que no son arrays', function (): void {
    $bus = inventoryCollectingBus();
    $dispatcher = new GoodsReceivedStockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryOutboxMessage('inbound.goods_received', [
        'warehouse_id' => 'wh-1',
        'lines' => [
            ['sku' => 'DIS-1', 'accepted' => 0],
            ['sku' => 'DIS-2'],
            ['accepted' => 7],
            'no-es-array',
            ['sku' => 'DIS-3', 'accepted' => 9],
        ],
    ]));

    expect($bus->commands)->toHaveCount(1);
    $command = $bus->commands[0];
    assert($command instanceof ReceiveStock);
    expect($command->sku)->toBe('DIS-3')
        ->and($command->quantity)->toBe(9);
});

it('no hace nada cuando el evento no es inbound.goods_received', function (): void {
    $bus = inventoryCollectingBus();
    $dispatcher = new GoodsReceivedStockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryOutboxMessage('otro.evento', [
        'warehouse_id' => 'wh-1',
        'lines' => [['sku' => 'DIS-1', 'accepted' => 5]],
    ]));

    expect($bus->commands)->toBe([]);
});

it('no hace nada cuando lines no es un array', function (): void {
    $bus = inventoryCollectingBus();
    $dispatcher = new GoodsReceivedStockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryOutboxMessage('inbound.goods_received', [
        'warehouse_id' => 'wh-1',
        'lines' => 'no-es-array',
    ]));

    expect($bus->commands)->toBe([]);
});
