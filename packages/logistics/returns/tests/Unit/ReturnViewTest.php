<?php

declare(strict_types=1);

use Logistics\Returns\Application\ReadModel\ReturnView;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;

/**
 * Proyección de lectura ReturnView (primitivos puros): fromDomain mapea el agregado y toArray expone la
 * forma de respuesta de la API. Sin base de datos: se construye el agregado en memoria.
 */
it('proyecta una devolución de seller (fbd) a primitivos con fromDomain', function (): void {
    $sellerId = '01931000-0000-7000-8000-0000000000aa';
    $warehouseId = '01931001-0000-7000-8000-0000000000bb';

    $returnOrder = ReturnOrder::initiate(
        ReturnId::fromString('01931002-0000-7000-8000-0000000000cc'),
        'SHP-VIEW-1',
        $sellerId,
        $warehouseId,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('BEL-TV-55', 2, 'damaged')],
    );

    $view = ReturnView::fromDomain($returnOrder);

    expect($view->id)->toBe('01931002-0000-7000-8000-0000000000cc')
        ->and($view->originalReference)->toBe('SHP-VIEW-1')
        ->and($view->sellerId)->toBe($sellerId)
        ->and($view->warehouseId)->toBe($warehouseId)
        ->and($view->fulfillmentMode)->toBe('fbd')
        ->and($view->kind)->toBe('customer_return')
        ->and($view->disposition)->toBeNull()
        ->and($view->status)->toBe('initiated')
        ->and($view->lines)->toBe([['sku' => 'BEL-TV-55', 'quantity' => 2, 'reason' => 'damaged']]);
});

it('serializa a array con la forma de la API, incluida la disposición ya decidida', function (): void {
    $warehouseId = '01931001-0000-7000-8000-0000000000dd';

    $returnOrder = ReturnOrder::initiate(
        ReturnId::fromString('01931002-0000-7000-8000-0000000000ee'),
        'SHP-VIEW-2',
        null,
        $warehouseId,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('DIS-FRIDGE-300', 1, 'customer_regret')],
    );
    $returnOrder->receive();
    $returnOrder->dispose(Disposition::Scrap);

    $array = ReturnView::fromDomain($returnOrder)->toArray();

    expect($array)->toBe([
        'id' => '01931002-0000-7000-8000-0000000000ee',
        'original_reference' => 'SHP-VIEW-2',
        'seller_id' => null,
        'warehouse_id' => $warehouseId,
        'fulfillment_mode' => 'owned',
        'kind' => 'customer_return',
        'disposition' => 'scrap',
        'status' => 'dispositioned',
        'lines' => [['sku' => 'DIS-FRIDGE-300', 'quantity' => 1, 'reason' => 'customer_regret']],
    ]);
});
