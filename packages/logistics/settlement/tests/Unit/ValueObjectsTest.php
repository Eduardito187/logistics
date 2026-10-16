<?php

declare(strict_types=1);

use Logistics\Settlement\Domain\Exception\SettlementException;
use Logistics\Settlement\Domain\ValueObject\AppliedFees;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementLine;
use Logistics\Settlement\Domain\ValueObject\SettlementPeriod;

it('arma un periodo válido y rechaza rangos invertidos o mal formados', function (): void {
    $period = SettlementPeriod::of('2026-09-01', '2026-09-30');

    expect($period->fromString())->toBe('2026-09-01')
        ->and($period->toStringEnd())->toBe('2026-09-30')
        ->and($period->label())->toBe('2026-09-01 — 2026-09-30');

    expect(fn () => SettlementPeriod::of('2026-09-30', '2026-09-01'))->toThrow(SettlementException::class);
    expect(fn () => SettlementPeriod::of('2026/09/01', '2026-09-30'))->toThrow(SettlementException::class);
});

it('arma una línea de liquidación y exige envío y referencia', function (): void {
    $line = SettlementLine::of('01931000-0000-7000-8000-000000000abc', 'ORD-123', Money::of(50_000));

    expect($line->toArray())->toMatchArray([
        'dispatch_reference' => 'ORD-123',
        'base_amount_cents' => 50_000,
        'currency' => 'BOB',
    ]);

    expect(fn () => SettlementLine::of('  ', 'ORD-1', Money::of(1)))->toThrow(SettlementException::class);
    expect(fn () => SettlementLine::of('ship-1', '  ', Money::of(1)))->toThrow(SettlementException::class);
});

it('suma los fees aplicados y deja los diferidos en cero', function (): void {
    $fees = AppliedFees::of(1500, Money::of(150_000), Money::of(3_000));

    expect($fees->storageFee->cents)->toBe(0)
        ->and($fees->fulfillmentFee->cents)->toBe(0)
        ->and($fees->lastMileFee->cents)->toBe(0)
        ->and($fees->total()->cents)->toBe(153_000)
        ->and($fees->toArray()['total_cents'])->toBe(153_000);
});
