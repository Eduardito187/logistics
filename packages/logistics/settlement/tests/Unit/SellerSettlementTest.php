<?php

declare(strict_types=1);

use Logistics\Settlement\Domain\Event\SettlementConfirmed;
use Logistics\Settlement\Domain\Event\SettlementDrafted;
use Logistics\Settlement\Domain\Event\SettlementPaid;
use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;
use Logistics\Settlement\Domain\ValueObject\SettlementStatus;

/**
 * @param  list<int>            $lineAmounts
 * @return list<SettlementLine>
 */
function settlementLines(array $lineAmounts): array
{
    $lines = [];
    foreach ($lineAmounts as $i => $amount) {
        $lines[] = SettlementLine::of(
            sprintf('01931000-0000-7000-8000-%012d', $i + 1),
            'ORD-'.($i + 1),
            Money::of($amount),
        );
    }

    return $lines;
}

function settlementDraft(int $commissionBp = 1500, int $fixedFee = 2_000): SellerSettlement
{
    return SellerSettlement::draft(
        SettlementId::generate(),
        '01931000-0000-7000-8000-0000000000aa',
        SettlementPeriod::of('2026-09-01', '2026-09-30'),
        settlementLines([600_000, 400_000]),
        $commissionBp,
        Money::of($fixedFee),
    );
}

it('arma el borrador agregando el bruto, la comisión y el fee fijo por despacho', function (): void {
    $settlement = settlementDraft(1500, 2_000);

    // bruto = 1.000.000; comisión 15% = 150.000; fee fijo 2.000 × 2 despachos = 4.000.
    expect($settlement->gross()->cents)->toBe(1_000_000)
        ->and($settlement->fees()->commission->cents)->toBe(150_000)
        ->and($settlement->fees()->fixedFee->cents)->toBe(4_000)
        ->and($settlement->net()->cents)->toBe(846_000)
        ->and($settlement->status())->toBe(SettlementStatus::Draft);
});

it('deja los fees diferidos (almacenaje/fulfillment/última milla) en cero', function (): void {
    $fees = settlementDraft()->fees();

    expect($fees->storageFee->cents)->toBe(0)
        ->and($fees->fulfillmentFee->cents)->toBe(0)
        ->and($fees->lastMileFee->cents)->toBe(0);
});

it('graba el evento SettlementDrafted con el resumen del periodo', function (): void {
    $events = settlementDraft()->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(SettlementDrafted::class)
        ->and($events[0]->eventName())->toBe('settlement.drafted')
        ->and($events[0]->lineCount)->toBe(2)
        ->and($events[0]->netCents)->toBe(846_000);
});

it('rechaza armar una liquidación sin despachos', function (): void {
    expect(fn () => SellerSettlement::draft(
        SettlementId::generate(),
        '01931000-0000-7000-8000-0000000000aa',
        SettlementPeriod::of('2026-09-01', '2026-09-30'),
        [],
        1500,
        Money::of(0),
    ))->toThrow(SettlementException::class);
});

it('rechaza fees que superan el bruto del periodo', function (): void {
    // fee fijo 700.000 × 2 = 1.400.000 > bruto 1.000.000.
    expect(fn () => settlementDraft(0, 700_000))->toThrow(SettlementException::class);
});

it('transiciona draft → confirmed → paid grabando sus eventos', function (): void {
    $settlement = settlementDraft();
    $settlement->releaseEvents();

    $settlement->confirm();
    expect($settlement->status())->toBe(SettlementStatus::Confirmed);

    $settlement->markPaid('TRX-9001');
    expect($settlement->status())->toBe(SettlementStatus::Paid)
        ->and($settlement->paymentReference())->toBe('TRX-9001');

    $events = $settlement->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(SettlementConfirmed::class)
        ->and($events[1])->toBeInstanceOf(SettlementPaid::class);
});

it('no permite confirmar fuera de borrador ni pagar sin confirmar', function (): void {
    $settlement = settlementDraft();

    expect(fn () => $settlement->markPaid('TRX-1'))->toThrow(SettlementException::class);

    $settlement->confirm();
    expect(fn () => $settlement->confirm())->toThrow(SettlementException::class);
    expect(fn () => $settlement->markPaid('  '))->toThrow(SettlementException::class);
});
