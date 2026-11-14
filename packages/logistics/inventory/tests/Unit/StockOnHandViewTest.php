<?php

declare(strict_types=1);

use Logistics\Inventory\Application\ReadModel\StockOnHandView;

/**
 * ReadModel del stock on-hand agregado por dueño: una fila por (modo de fulfillment, dueño). Cubre el
 * hidratado desde la fila agregada del read store y la forma primitiva.
 */
it('hidrata la vista agregada desde una fila del read store', function (): void {
    $view = StockOnHandView::fromRow([
        'fulfillment_mode' => 'owned',
        'owner_party_id' => null,
        'available' => 70,
        'reserved' => 10,
        'on_hand' => 80,
        'lots' => 3,
    ]);

    expect($view->fulfillmentMode)->toBe('owned')
        ->and($view->ownerPartyId)->toBeNull()
        ->and($view->available)->toBe(70)
        ->and($view->reserved)->toBe(10)
        ->and($view->onHand)->toBe(80)
        ->and($view->lots)->toBe(3);
});

it('exporta su forma primitiva conservando el dueño del seller', function (): void {
    $view = StockOnHandView::fromRow([
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => 'seller-1',
        'available' => 25,
        'reserved' => 5,
        'on_hand' => 30,
        'lots' => 1,
    ]);

    expect($view->toArray())->toBe([
        'fulfillment_mode' => 'fbd',
        'owner_party_id' => 'seller-1',
        'available' => 25,
        'reserved' => 5,
        'on_hand' => 30,
        'lots' => 1,
    ]);
});
