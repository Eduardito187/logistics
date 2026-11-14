<?php

declare(strict_types=1);

use Logistics\Inventory\Application\ReadModel\AvailableToPromiseView;

/**
 * ReadModel del Available-to-Promise: proyección de lectura con primitivos. Cubre la construcción y la
 * forma primitiva (toArray) tanto con dueño como sin dueño (stock propio).
 */
it('expone los campos del available-to-promise con dueño', function (): void {
    $view = new AvailableToPromiseView(
        warehouseId: 'wh-1',
        sku: 'BEL-TV-55',
        ownerPartyId: 'seller-1',
        availableToPromise: 50,
    );

    expect($view->warehouseId)->toBe('wh-1')
        ->and($view->sku)->toBe('BEL-TV-55')
        ->and($view->ownerPartyId)->toBe('seller-1')
        ->and($view->availableToPromise)->toBe(50);
});

it('exporta su forma primitiva con dueño', function (): void {
    $view = new AvailableToPromiseView('wh-1', 'BEL-TV-55', 'seller-1', 50);

    expect($view->toArray())->toBe([
        'warehouse_id' => 'wh-1',
        'sku' => 'BEL-TV-55',
        'owner_party_id' => 'seller-1',
        'available_to_promise' => 50,
    ]);
});

it('exporta su forma primitiva con dueño nulo (stock propio)', function (): void {
    $view = new AvailableToPromiseView('wh-1', 'DIS-WASH-900', null, 0);

    expect($view->toArray())->toBe([
        'warehouse_id' => 'wh-1',
        'sku' => 'DIS-WASH-900',
        'owner_party_id' => null,
        'available_to_promise' => 0,
    ]);
});
