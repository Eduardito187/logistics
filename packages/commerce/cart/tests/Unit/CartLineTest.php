<?php

declare(strict_types=1);

use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartLine;
use Commerce\Cart\Domain\ValueObject\Sku;

it('Sku normaliza a mayúsculas sin espacios y rechaza vacío', function (): void {
    $sku = Sku::of('  tv-55 ');
    expect($sku->value)->toBe('TV-55')
        ->and($sku->equals(Sku::of('tv-55')))->toBeTrue();

    expect(fn () => Sku::of('   '))->toThrow(CartException::class);
});

it('CartLine calcula subtotal, hace round-trip por array y valida sus invariantes', function (): void {
    $line = CartLine::of(Sku::of('sku-tv-55'), 'Televisor LED 55"', 150000, 2);

    expect($line->subtotalCents())->toBe(300000);
    expect($line->toArray())->toBe([
        'sku' => 'SKU-TV-55',
        'product_name' => 'Televisor LED 55"',
        'unit_price_cents' => 150000,
        'qty' => 2,
        'subtotal_cents' => 300000,
    ]);

    $again = CartLine::fromArray($line->toArray());
    expect($again->sku->value)->toBe('SKU-TV-55')
        ->and($again->qty)->toBe(2)
        ->and($again->unitPriceCents)->toBe(150000);
});

it('CartLine rechaza nombre vacío, precio negativo y cantidad menor a 1', function (): void {
    expect(fn () => CartLine::of(Sku::of('X-1'), '  ', 100, 1))->toThrow(CartException::class);
    expect(fn () => CartLine::of(Sku::of('X-1'), 'A', -1, 1))->toThrow(CartException::class);
    expect(fn () => CartLine::of(Sku::of('X-1'), 'A', 100, 0))->toThrow(CartException::class);
});

it('withQty devuelve una copia con otra cantidad sin mutar la original', function (): void {
    $line = CartLine::of(Sku::of('X-1'), 'A', 100, 1);
    $bumped = $line->withQty(5);

    expect($bumped->qty)->toBe(5)
        ->and($line->qty)->toBe(1)
        ->and($bumped->subtotalCents())->toBe(500);
});
