<?php

declare(strict_types=1);

use Logistics\Returns\Domain\Event\ReturnDispositioned;
use Logistics\Returns\Domain\Event\ReturnInitiated;
use Logistics\Returns\Domain\Event\ReturnReceived;
use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;

const RETURNS_SELLER_ID = '01931000-0000-7000-8000-000000000001';
const RETURNS_WAREHOUSE_ID = '01931001-0000-7000-8000-0000000000f1';

function returnsSellerCustomerReturn(): ReturnOrder
{
    return ReturnOrder::initiate(
        ReturnId::generate(),
        'SHP-1001',
        RETURNS_SELLER_ID,
        RETURNS_WAREHOUSE_ID,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('BEL-TV-55', 2, 'damaged')],
    );
}

it('inicia una devolución en Initiated y graba el evento con la propiedad del seller (fbd)', function (): void {
    $returnOrder = returnsSellerCustomerReturn();

    expect($returnOrder->status())->toBe(ReturnStatus::Initiated)
        ->and($returnOrder->lines())->toHaveCount(1)
        ->and($returnOrder->ownerPartyId())->toBe(RETURNS_SELLER_ID)
        ->and($returnOrder->isMarketplace())->toBeTrue();

    $events = $returnOrder->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ReturnInitiated::class)
        ->and($events[0]->eventName())->toBe('returns.return_initiated')
        ->and($events[0]->payload()['fulfillment_mode'])->toBe('fbd')
        ->and($events[0]->payload()['owner_party_id'])->toBe(RETURNS_SELLER_ID);
});

it('una devolución retail (sin dueño) queda owned', function (): void {
    $returnOrder = ReturnOrder::initiate(
        ReturnId::generate(),
        'SHP-1002',
        null,
        RETURNS_WAREHOUSE_ID,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('DIS-FRIDGE-300', 1, 'customer_regret')],
    );

    expect($returnOrder->ownerPartyId())->toBeNull()
        ->and($returnOrder->isMarketplace())->toBeFalse()
        ->and($returnOrder->ownership()->mode->value)->toBe('owned');
});

it('rechaza iniciar sin referencia, sin almacén o sin líneas', function (callable $build): void {
    expect($build)->toThrow(ReturnsException::class);
})->with([
    'sin referencia' => [fn () => ReturnOrder::initiate(ReturnId::generate(), '  ', null, RETURNS_WAREHOUSE_ID, ReturnKind::CustomerReturn, [ReturnLine::of('X', 1, 'r')])],
    'sin almacén' => [fn () => ReturnOrder::initiate(ReturnId::generate(), 'SHP-1', null, '  ', ReturnKind::CustomerReturn, [ReturnLine::of('X', 1, 'r')])],
    'sin líneas' => [fn () => ReturnOrder::initiate(ReturnId::generate(), 'SHP-1', null, RETURNS_WAREHOUSE_ID, ReturnKind::CustomerReturn, [])],
]);

it('un RTV exige un seller dueño', function (): void {
    expect(fn () => ReturnOrder::initiate(
        ReturnId::generate(),
        'SHP-1003',
        null,
        RETURNS_WAREHOUSE_ID,
        ReturnKind::ReturnToVendor,
        [ReturnLine::of('BEL-TV-55', 1, 'defective')],
    ))->toThrow(ReturnsException::class);
});

it('recorre el ciclo de vida completo: initiate → receive → dispose(restock) → close', function (): void {
    $returnOrder = returnsSellerCustomerReturn();
    $returnOrder->releaseEvents();

    $returnOrder->receive();
    $returnOrder->dispose(Disposition::Restock);
    $returnOrder->close();

    expect($returnOrder->status())->toBe(ReturnStatus::Closed)
        ->and($returnOrder->disposition())->toBe(Disposition::Restock);

    $events = $returnOrder->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(ReturnReceived::class)
        ->and($events[1])->toBeInstanceOf(ReturnDispositioned::class);
});

it('al disponer Restock el evento trae lo que inventory necesita para ReceiveStock', function (): void {
    $returnOrder = returnsSellerCustomerReturn();
    $returnOrder->receive();
    $returnOrder->releaseEvents();

    $returnOrder->dispose(Disposition::Restock);
    $events = $returnOrder->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ReturnDispositioned::class);

    $payload = $events[0]->payload();
    expect($payload['disposition'])->toBe('restock')
        ->and($payload['restock'])->toBeTrue()
        ->and($payload['warehouse_id'])->toBe(RETURNS_WAREHOUSE_ID)
        ->and($payload['fulfillment_mode'])->toBe('fbd')
        ->and($payload['owner_party_id'])->toBe(RETURNS_SELLER_ID)
        ->and($payload['lines'])->toBe([['sku' => 'BEL-TV-55', 'quantity' => 2]]);
});

it('al disponer Scrap no se emiten líneas de restock', function (): void {
    $returnOrder = returnsSellerCustomerReturn();
    $returnOrder->receive();
    $returnOrder->releaseEvents();

    $returnOrder->dispose(Disposition::Scrap);
    $events = $returnOrder->releaseEvents();

    $payload = $events[0]->payload();
    expect($payload['disposition'])->toBe('scrap')
        ->and($payload['restock'])->toBeFalse()
        ->and($payload['lines'])->toBe([]);
});

it('no se puede devolver al seller una devolución retail (sin dueño)', function (): void {
    $returnOrder = ReturnOrder::initiate(
        ReturnId::generate(),
        'SHP-1004',
        null,
        RETURNS_WAREHOUSE_ID,
        ReturnKind::CustomerReturn,
        [ReturnLine::of('DIS-FRIDGE-300', 1, 'damaged')],
    );
    $returnOrder->receive();
    $returnOrder->releaseEvents();

    expect(fn () => $returnOrder->dispose(Disposition::ReturnToSeller))->toThrow(ReturnsException::class);
});

it('rechaza transiciones inválidas', function (): void {
    $returnOrder = returnsSellerCustomerReturn();

    // No se puede disponer ni cerrar antes de recibir.
    expect(fn () => $returnOrder->dispose(Disposition::Restock))->toThrow(ReturnsException::class)
        ->and(fn () => $returnOrder->close())->toThrow(ReturnsException::class);
});

it('receive es idempotente y no re-emite', function (): void {
    $returnOrder = returnsSellerCustomerReturn();
    $returnOrder->releaseEvents();

    $returnOrder->receive();
    expect($returnOrder->releaseEvents())->toHaveCount(1);

    $returnOrder->receive();
    expect($returnOrder->releaseEvents())->toBe([]);
});
