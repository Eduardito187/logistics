<?php

declare(strict_types=1);

use Logistics\Inventory\Application\ReadModel\LotView;

/**
 * ReadModel de un lote de stock para listados y API: primitivos, sin objetos de dominio. Cubre el
 * hidratado desde una fila (fromRow) y la forma primitiva (toArray), incluido el costo unitario anidado.
 */
function inventoryLotRow(): array
{
    return [
        'id' => '0192aaaa-bbbb-7ccc-8ddd-000000000001',
        'warehouse_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000c1',
        'sku' => 'BEL-TV-55',
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => 'seller-1',
        'seller_sku_ref' => 'SELLER-REF-9',
        'available_quantity' => 40,
        'reserved_quantity' => 10,
        'on_hand' => 50,
        'unit_cost_cents' => 320000,
        'currency' => 'BOB',
        'received_at' => '2026-10-16 10:00:00',
        'goods_receipt_ref' => 'GR-0001',
    ];
}

it('hidrata la vista desde una fila del read store', function (): void {
    $view = LotView::fromRow(inventoryLotRow());

    expect($view->id)->toBe('0192aaaa-bbbb-7ccc-8ddd-000000000001')
        ->and($view->warehouseId)->toBe('0192aaaa-bbbb-7ccc-8ddd-0000000000c1')
        ->and($view->sku)->toBe('BEL-TV-55')
        ->and($view->fulfillmentMode)->toBe('fbd')
        ->and($view->ownerPartyId)->toBe('seller-1')
        ->and($view->sellerSkuRef)->toBe('SELLER-REF-9')
        ->and($view->availableQuantity)->toBe(40)
        ->and($view->reservedQuantity)->toBe(10)
        ->and($view->onHand)->toBe(50)
        ->and($view->unitCostCents)->toBe(320000)
        ->and($view->currency)->toBe('BOB')
        ->and($view->receivedAt)->toBe('2026-10-16 10:00:00')
        ->and($view->goodsReceiptRef)->toBe('GR-0001');
});

it('exporta su forma primitiva con el costo unitario anidado', function (): void {
    $view = LotView::fromRow(inventoryLotRow());

    expect($view->toArray())->toBe([
        'id' => '0192aaaa-bbbb-7ccc-8ddd-000000000001',
        'warehouse_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000c1',
        'sku' => 'BEL-TV-55',
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => 'seller-1',
        'seller_sku_ref' => 'SELLER-REF-9',
        'available_quantity' => 40,
        'reserved_quantity' => 10,
        'on_hand' => 50,
        'unit_cost' => ['cents' => 320000, 'currency' => 'BOB'],
        'received_at' => '2026-10-16 10:00:00',
        'goods_receipt_ref' => 'GR-0001',
    ]);
});

it('tolera los campos opcionales nulos del lote propio', function (): void {
    $row = inventoryLotRow();
    $row['owner_party_id'] = null;
    $row['seller_sku_ref'] = null;
    $row['goods_receipt_ref'] = null;

    $view = LotView::fromRow($row);
    $array = $view->toArray();

    expect($view->ownerPartyId)->toBeNull()
        ->and($array['owner_party_id'])->toBeNull()
        ->and($array['seller_sku_ref'])->toBeNull()
        ->and($array['goods_receipt_ref'])->toBeNull();
});
