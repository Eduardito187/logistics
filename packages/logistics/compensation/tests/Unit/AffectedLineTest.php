<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\Money;

// Snapshot de una línea afectada: SIEMPRE nombre + SKU, nunca vacío.

it('construye una línea con nombre, SKU y valor por cantidad', function (): void {
    $line = AffectedLine::of('Refrigerador Mabe 300L', 'SKU-77231', 2, Money::of(50_000));

    expect($line->productName)->toBe('Refrigerador Mabe 300L')
        ->and($line->sku)->toBe('SKU-77231')
        ->and($line->affectedQuantity)->toBe(2)
        ->and($line->lineValue()->cents)->toBe(100_000);
});

it('recorta espacios en nombre y SKU', function (): void {
    $line = AffectedLine::of('  Lavadora LG  ', '  SKU-LG18  ', 1, Money::of(1_000));

    expect($line->productName)->toBe('Lavadora LG')
        ->and($line->sku)->toBe('SKU-LG18');
});

it('rechaza nombre vacío', function (): void {
    expect(fn () => AffectedLine::of('   ', 'SKU-1', 1, Money::of(1_000)))
        ->toThrow(CompensationException::class);
});

it('rechaza SKU vacío', function (): void {
    expect(fn () => AffectedLine::of('Producto', '  ', 1, Money::of(1_000)))
        ->toThrow(CompensationException::class);
});

it('rechaza cantidad afectada menor a 1', function (): void {
    expect(fn () => AffectedLine::of('Producto', 'SKU-1', 0, Money::of(1_000)))
        ->toThrow(CompensationException::class);
});

it('viaja a array y vuelve idéntico', function (): void {
    $line = AffectedLine::of('Televisor Samsung 55"', 'SKU-55TV', 3, Money::of(20_000));
    $array = $line->toArray();

    expect($array)->toBe([
        'product_name' => 'Televisor Samsung 55"',
        'sku' => 'SKU-55TV',
        'affected_quantity' => 3,
        'unit_price_cents' => 20_000,
        'currency' => 'BOB',
    ]);

    $restored = AffectedLine::fromArray($array);
    expect($restored->toArray())->toBe($array);
});
