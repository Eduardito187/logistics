<?php

declare(strict_types=1);

use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Subscriber\ReceiveStockOnGoodsReceived;
use Logistics\Inventory\Infrastructure\Dispatch\ReturnRestockDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Adaptador de logística inversa returns.return_dispositioned → ReceiveStock. Solo reingresa stock cuando
 * la disposición es restock (`restock === true`); return_to_seller/scrap NO reingresan. Cada línea
 * `{sku, quantity}` se mapea a su forma plana con el dueño correcto. PHP puro con bus que captura comandos.
 */

/**
 * CommandBus de prueba que acumula los comandos despachados por el adaptador de returns.
 *
 * @return CommandBus&object{commands: list<Command>}
 */
function inventoryReturnBus(): CommandBus
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
 * Construye un mensaje de outbox Pending de returns con el payload dado.
 *
 * @param array<string, mixed> $payload
 */
function inventoryReturnMessage(array $payload, string $eventName = 'returns.return_dispositioned'): OutboxMessage
{
    $now = new DateTimeImmutable('2026-10-16T10:00:00+00:00');

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'returns.return',
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

it('reingresa el stock con su dueño cuando la disposición es restock', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'restock' => true,
        'warehouse_id' => 'wh-1',
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => 'seller-1',
        'return_id' => 'RET-0001',
        'lines' => [
            ['sku' => 'BEL-TV-55', 'quantity' => 2],
            ['sku' => 'BEL-FRIDGE-10', 'quantity' => 1],
        ],
    ]));

    expect($bus->commands)->toHaveCount(2);
    $first = $bus->commands[0];
    assert($first instanceof ReceiveStock);
    expect($first->sku)->toBe('BEL-TV-55')
        ->and($first->quantity)->toBe(2)
        ->and($first->fulfillmentMode)->toBe('fbd')
        ->and($first->ownerPartyId)->toBe('seller-1')
        ->and($first->goodsReceiptRef)->toBe('RET-0001');
});

it('aplica owned por defecto cuando el restock no trae modo de fulfillment', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'restock' => true,
        'warehouse_id' => 'wh-1',
        'return_id' => 'RET-0002',
        'lines' => [['sku' => 'DIS-1', 'quantity' => 3]],
    ]));

    $command = $bus->commands[0];
    assert($command instanceof ReceiveStock);
    expect($command->fulfillmentMode)->toBe('owned')
        ->and($command->ownerPartyId)->toBeNull();
});

it('no reingresa stock cuando la disposición no es restock', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'restock' => false,
        'warehouse_id' => 'wh-1',
        'lines' => [['sku' => 'DIS-1', 'quantity' => 3]],
    ]));

    expect($bus->commands)->toBe([]);
});

it('no reingresa stock cuando falta la bandera restock (return_to_seller/scrap)', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'warehouse_id' => 'wh-1',
        'lines' => [['sku' => 'DIS-1', 'quantity' => 3]],
    ]));

    expect($bus->commands)->toBe([]);
});

it('ignora líneas sin cantidad positiva, sin sku o que no son arrays', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'restock' => true,
        'warehouse_id' => 'wh-1',
        'return_id' => 'RET-0003',
        'lines' => [
            ['sku' => 'DIS-1', 'quantity' => 0],
            ['sku' => 'DIS-2'],
            ['quantity' => 4],
            'no-es-array',
            ['sku' => 'DIS-3', 'quantity' => 6],
        ],
    ]));

    expect($bus->commands)->toHaveCount(1);
    $command = $bus->commands[0];
    assert($command instanceof ReceiveStock);
    expect($command->sku)->toBe('DIS-3')
        ->and($command->quantity)->toBe(6);
});

it('no hace nada cuando el evento no es returns.return_dispositioned', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage(['restock' => true, 'lines' => []], 'otro.evento'));

    expect($bus->commands)->toBe([]);
});

it('no hace nada cuando lines no es un array', function (): void {
    $bus = inventoryReturnBus();
    $dispatcher = new ReturnRestockDispatcher(new ReceiveStockOnGoodsReceived($bus));

    $dispatcher->dispatch(inventoryReturnMessage([
        'restock' => true,
        'warehouse_id' => 'wh-1',
        'lines' => 'no-es-array',
    ]));

    expect($bus->commands)->toBe([]);
});
