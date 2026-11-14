<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\SellerSku;

/**
 * Cobertura del value object SellerSku del contexto sellers: normalización del código, material SAP
 * opcional, igualdad y serialización primitiva. PHP puro, sin Laravel.
 */
it('normaliza el SKU y deja el material SAP en null cuando viene vacío', function (): void {
    $sku = SellerSku::of('  BEL-TV-55  ', '  ');

    expect($sku->sku)->toBe('BEL-TV-55')
        ->and($sku->sapMaterial)->toBeNull();
});

it('conserva el material SAP cuando se mapea', function (): void {
    $sku = SellerSku::of('BEL-TV-55', '  MAT-900155 ');

    expect($sku->sku)->toBe('BEL-TV-55')
        ->and($sku->sapMaterial)->toBe('MAT-900155')
        ->and($sku->toArray())->toBe(['sku' => 'BEL-TV-55', 'sap_material' => 'MAT-900155']);
});

it('rechaza un SKU vacío', function (): void {
    expect(fn () => SellerSku::of('   '))->toThrow(SellersException::class);
});

it('compara por valor', function (): void {
    $base = SellerSku::of('BEL-TV-55', 'MAT-900155');

    expect($base->equals(SellerSku::of('BEL-TV-55', 'MAT-900155')))->toBeTrue()
        ->and($base->equals(SellerSku::of('BEL-TV-55')))->toBeFalse()
        ->and($base->equals(SellerSku::of('OTRO-SKU', 'MAT-900155')))->toBeFalse();
});
