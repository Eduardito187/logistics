<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Event\GoodsReceived;
use Logistics\Inbound\Domain\Event\UnitsLabeled;
use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;
use Logistics\Inbound\Domain\ValueObject\QcOutcome;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

function inboundOwnership(): Ownership
{
    return Ownership::sellerFbd(Uuid::fromString('01931000-0000-7000-8000-000000000001'), 'BEL-TV-55');
}

function inboundReceipt(): GoodsReceipt
{
    return GoodsReceipt::record(
        GoodsReceiptId::generate(),
        AsnId::generate(),
        '01931001-0000-7000-8000-0000000000f1',
        inboundOwnership(),
        [ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage)],
    );
}

it('registra una recepción FBD y graba GoodsReceived con la propiedad', function (): void {
    $receipt = inboundReceipt();

    expect($receipt->totalAccepted())->toBe(9)
        ->and($receipt->isLabeled())->toBeFalse();

    $events = $receipt->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GoodsReceived::class)
        ->and($events[0]->eventName())->toBe('inbound.goods_received')
        ->and($events[0]->payload()['owner_party_id'])->toBe('01931000-0000-7000-8000-000000000001')
        ->and($events[0]->payload()['fulfillment_mode'])->toBe('fbd');
});

it('rechaza registrar una recepción de stock propio (no marketplace)', function (): void {
    expect(fn () => GoodsReceipt::record(
        GoodsReceiptId::generate(),
        AsnId::generate(),
        'wh',
        Ownership::dismacOwned(),
        [ReceiptLine::of('X', ReceivedQuantity::of(1), QcOutcome::Accepted)],
    ))->toThrow(InboundException::class);
});

it('etiqueta las unidades aceptadas y graba UnitsLabeled', function (): void {
    $receipt = inboundReceipt();
    $receipt->releaseEvents();

    $receipt->labelUnits();

    expect($receipt->isLabeled())->toBeTrue();

    $events = $receipt->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(UnitsLabeled::class)
        ->and($events[0]->eventName())->toBe('inbound.units_labeled')
        ->and($events[0]->payload()['units'])->toBe([['sku' => 'BEL-TV-55', 'quantity' => 9]]);
});

it('el etiquetado es idempotente', function (): void {
    $receipt = inboundReceipt();
    $receipt->releaseEvents();

    $receipt->labelUnits();
    $receipt->releaseEvents();
    $receipt->labelUnits();

    expect($receipt->releaseEvents())->toBe([]);
});

it('no etiqueta si no hay nada aceptado', function (): void {
    $receipt = GoodsReceipt::record(
        GoodsReceiptId::generate(),
        AsnId::generate(),
        'wh',
        inboundOwnership(),
        [ReceiptLine::of('X', ReceivedQuantity::of(0, 3, 0), QcOutcome::Rejected)],
    );
    $receipt->releaseEvents();

    expect(fn () => $receipt->labelUnits())->toThrow(InboundException::class);
});
