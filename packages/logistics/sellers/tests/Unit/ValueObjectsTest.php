<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Domain\ValueObject\PaymentCycle;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;

it('Money por defecto es BOB y suma exacto en centavos', function (): void {
    $a = Money::of(1500);
    expect($a->currency)->toBe('BOB')
        ->and($a->cents)->toBe(1500);

    $b = Money::of(500, 'BOB');
    expect($a->add($b)->cents)->toBe(2000);
});

it('Money rechaza montos negativos y operar monedas distintas', function (): void {
    expect(fn () => Money::of(-1))->toThrow(SellersException::class);
    expect(fn () => Money::of(100, 'BOB')->add(Money::of(100, 'USD')))->toThrow(SellersException::class);
});

it('SettlementTerms exige comisión dentro de 0..10000 bp', function (): void {
    $terms = SettlementTerms::of(1500, Money::of(0), PaymentCycle::Monthly);
    expect($terms->commissionPercent())->toBe(15.0)
        ->and($terms->toArray()['commission_basis_points'])->toBe(1500);

    expect(fn () => SettlementTerms::of(10001, Money::of(0), PaymentCycle::Monthly))
        ->toThrow(SellersException::class);
    expect(fn () => SettlementTerms::of(-1, Money::of(0), PaymentCycle::Monthly))
        ->toThrow(SellersException::class);
});

it('SellerSku normaliza vacío a error y sap material vacío a null', function (): void {
    $sku = SellerSku::of('  BEL-TV-55  ', '  ');
    expect($sku->sku)->toBe('BEL-TV-55')
        ->and($sku->sapMaterial)->toBeNull();

    expect(fn () => SellerSku::of('   '))->toThrow(SellersException::class);
});

it('ContactInfo valida el correo y deja el teléfono opcional', function (): void {
    $contact = ContactInfo::of('Ana', 'ana@seller.bo');
    expect($contact->phone)->toBeNull()
        ->and($contact->toArray()['email'])->toBe('ana@seller.bo');

    expect(fn () => ContactInfo::of('Ana', 'no-es-correo'))->toThrow(SellersException::class);
});
