<?php

declare(strict_types=1);

use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\Sku;
use Commerce\Pricing\Domain\ValueObject\TaxPolicy;

it('Money por defecto es BOB y suma exacto en centavos', function (): void {
    $a = Money::of(1500);
    expect($a->currency)->toBe('BOB')
        ->and($a->cents)->toBe(1500);

    $b = Money::of(500, 'BOB');
    expect($a->add($b)->cents)->toBe(2000);
});

it('Money rechaza montos negativos y operar monedas distintas', function (): void {
    expect(fn () => Money::of(-1))->toThrow(PricingException::class);
    expect(fn () => Money::of(100, 'BOB')->add(Money::of(100, 'USD')))->toThrow(PricingException::class);
    expect(fn () => Money::of(100)->subtract(Money::of(200)))->toThrow(PricingException::class);
});

it('Sku normaliza a mayúsculas sin espacios y rechaza vacío', function (): void {
    $sku = Sku::of('  bel-tv-55  ');
    expect($sku->value)->toBe('BEL-TV-55')
        ->and((string) $sku)->toBe('BEL-TV-55');

    expect(fn () => Sku::of('   '))->toThrow(PricingException::class);
});

it('TaxPolicy descompone el IVA 13 % incluido (por dentro) con redondeo a centavos', function (): void {
    $policy = TaxPolicy::bolivia();

    // 100,00 BOB con IVA incluido: IVA 13,00 / neto 87,00.
    $gross = Money::of(10000);
    expect($policy->ivaOn($gross)->cents)->toBe(1300)
        ->and($policy->netOf($gross)->cents)->toBe(8700)
        // El neto + el IVA reconstruye el bruto exacto (sin pérdida de centavos).
        ->and($policy->netOf($gross)->add($policy->ivaOn($gross))->cents)->toBe(10000);

    // 4500,00 BOB: IVA 585,00 / neto 3915,00.
    $tv = Money::of(450000);
    expect($policy->ivaOn($tv)->cents)->toBe(58500)
        ->and($policy->netOf($tv)->cents)->toBe(391500);

    // Caso con redondeo: 9,99 BOB → IVA round(129,87)=130 ; neto 8,69.
    $odd = Money::of(999);
    expect($policy->ivaOn($odd)->cents)->toBe(130)
        ->and($policy->netOf($odd)->cents)->toBe(869);
});

it('TaxPolicy calcula el IT 3 % sobre el bruto con redondeo exacto', function (): void {
    $policy = TaxPolicy::bolivia();

    expect($policy->transactionTaxOn(Money::of(10000))->cents)->toBe(300)
        ->and($policy->transactionTaxOn(Money::of(450000))->cents)->toBe(13500)
        // round(29,97) = 30
        ->and($policy->transactionTaxOn(Money::of(999))->cents)->toBe(30);
});

it('TaxPolicy expone el desglose primitivo completo (IVA + IT con sus tasas)', function (): void {
    $breakdown = TaxPolicy::bolivia()->breakdown(Money::of(12345));

    // IVA round(1604,85)=1605 ; IT round(370,35)=370.
    expect($breakdown)->toBe([
        'iva_cents' => 1605,
        'iva_basis_points' => 1300,
        'it_cents' => 370,
        'it_basis_points' => 300,
    ]);
});

it('TaxPolicy es configurable por tasas y rechaza tasas fuera de 0..10000 bp', function (): void {
    // Sin IVA, IT nominal: el neto es el bruto completo.
    $noIva = TaxPolicy::of(0, 300);
    expect($noIva->ivaOn(Money::of(10000))->cents)->toBe(0)
        ->and($noIva->netOf(Money::of(10000))->cents)->toBe(10000);

    expect(fn () => TaxPolicy::of(10001, 300))->toThrow(PricingException::class);
    expect(fn () => TaxPolicy::of(1300, -1))->toThrow(PricingException::class);
});
