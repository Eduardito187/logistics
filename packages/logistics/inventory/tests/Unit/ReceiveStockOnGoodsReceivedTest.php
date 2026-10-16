<?php

declare(strict_types=1);

use Logistics\Inventory\Application\Command\ReceiveStock\ReceiveStock;
use Logistics\Inventory\Application\Subscriber\ReceiveStockOnGoodsReceived;
use Logistics\Inventory\Domain\Exception\InventoryException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * CommandBus de prueba que captura el último comando despachado (sin Laravel).
 */
function inventoryCapturingBus(): CommandBus
{
    return new class implements CommandBus
    {
        public ?Command $last = null;

        public function dispatch(Command $command): void
        {
            $this->last = $command;
        }
    };
}

it('traduce el payload de inbound.goods_received a un comando ReceiveStock con el dueño correcto', function (): void {
    $bus = inventoryCapturingBus();
    $subscriber = new ReceiveStockOnGoodsReceived($bus);

    expect($subscriber->subscribedTo())->toBe('inbound.goods_received');

    $subscriber->handle([
        'warehouse_id' => '01931000-0000-7000-8000-00000000c001',
        'sku' => 'BEL-TV-55',
        'accepted_quantity' => 40,
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => '01931000-0000-7000-8000-000000000001',
        'seller_sku_ref' => 'BEL-TV-55',
        'unit_cost_cents' => 320000,
        'currency' => 'BOB',
        'goods_receipt_ref' => 'GR-0001',
    ]);

    $command = $bus->last;
    expect($command)->toBeInstanceOf(ReceiveStock::class);
    assert($command instanceof ReceiveStock);
    expect($command->warehouseId)->toBe('01931000-0000-7000-8000-00000000c001')
        ->and($command->sku)->toBe('BEL-TV-55')
        ->and($command->quantity)->toBe(40)
        ->and($command->fulfillmentMode)->toBe('fbd')
        ->and($command->ownerPartyId)->toBe('01931000-0000-7000-8000-000000000001')
        ->and($command->unitCostCents)->toBe(320000)
        ->and($command->goodsReceiptRef)->toBe('GR-0001');
});

it('aplica defaults (owned/BOB) cuando el payload trae lo mínimo', function (): void {
    $bus = inventoryCapturingBus();
    (new ReceiveStockOnGoodsReceived($bus))->handle([
        'warehouse_id' => 'wh-1',
        'sku' => 'DIS-1',
        'accepted_quantity' => 5,
    ]);

    $command = $bus->last;
    assert($command instanceof ReceiveStock);
    expect($command->fulfillmentMode)->toBe('owned')
        ->and($command->ownerPartyId)->toBeNull()
        ->and($command->currency)->toBe('BOB')
        ->and($command->unitCostCents)->toBe(0);
});

it('falla si el payload no trae los campos requeridos', function (): void {
    $bus = inventoryCapturingBus();
    $subscriber = new ReceiveStockOnGoodsReceived($bus);

    expect(fn () => $subscriber->handle(['sku' => 'X', 'accepted_quantity' => 1]))
        ->toThrow(InventoryException::class, 'warehouse_id');
});
