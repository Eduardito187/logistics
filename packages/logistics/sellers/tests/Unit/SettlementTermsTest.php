<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Domain\ValueObject\PaymentCycle;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;

/**
 * Cobertura del value object SettlementTerms del contexto sellers: comisión en basis points acotada,
 * porcentaje legible, reconstitución desde primitivos, igualdad y serialización. PHP puro, sin Laravel.
 */
it('pacta términos válidos y expone la comisión como porcentaje', function (): void {
    $terms = SettlementTerms::of(1500, Money::of(2000), PaymentCycle::Biweekly);

    expect($terms->commissionBasisPoints)->toBe(1500)
        ->and($terms->commissionPercent())->toBe(15.0)
        ->and($terms->fixedFee->cents)->toBe(2000)
        ->and($terms->paymentCycle)->toBe(PaymentCycle::Biweekly);
});

it('acota la comisión al rango 0..10000 basis points', function (): void {
    expect(SettlementTerms::of(0, Money::of(0), PaymentCycle::Monthly)->commissionBasisPoints)->toBe(0);
    expect(SettlementTerms::of(10000, Money::of(0), PaymentCycle::Monthly)->commissionBasisPoints)->toBe(10000);
    expect(fn () => SettlementTerms::of(-1, Money::of(0), PaymentCycle::Monthly))
        ->toThrow(SellersException::class);
    expect(fn () => SettlementTerms::of(10001, Money::of(0), PaymentCycle::Monthly))
        ->toThrow(SellersException::class);
});

it('se reconstituye desde primitivos (mappers de infraestructura)', function (): void {
    $terms = SettlementTerms::fromPrimitives(1800, 2500, 'BOB', 'monthly');

    expect($terms->commissionBasisPoints)->toBe(1800)
        ->and($terms->fixedFee->cents)->toBe(2500)
        ->and($terms->fixedFee->currency)->toBe('BOB')
        ->and($terms->paymentCycle)->toBe(PaymentCycle::Monthly)
        ->and($terms->toArray())->toBe([
            'commission_basis_points' => 1800,
            'fixed_fee' => ['cents' => 2500, 'currency' => 'BOB'],
            'payment_cycle' => 'monthly',
        ]);
});

it('compara por valor', function (): void {
    $base = SettlementTerms::of(1500, Money::of(2000), PaymentCycle::Monthly);

    expect($base->equals(SettlementTerms::of(1500, Money::of(2000), PaymentCycle::Monthly)))->toBeTrue()
        ->and($base->equals(SettlementTerms::of(1200, Money::of(2000), PaymentCycle::Monthly)))->toBeFalse()
        ->and($base->equals(SettlementTerms::of(1500, Money::of(2001), PaymentCycle::Monthly)))->toBeFalse()
        ->and($base->equals(SettlementTerms::of(1500, Money::of(2000), PaymentCycle::Weekly)))->toBeFalse();
});
