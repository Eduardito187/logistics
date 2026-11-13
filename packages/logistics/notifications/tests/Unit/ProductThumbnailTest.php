<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;

it('construye un producto recortando nombre, url y sku', function (): void {
    $product = ProductThumbnail::of('  Taladro  ', 2, '  https://img/1.png  ', '  SKU-1  ');

    expect($product->name)->toBe('Taladro')
        ->and($product->qty)->toBe(2)
        ->and($product->imageUrl)->toBe('https://img/1.png')
        ->and($product->sku)->toBe('SKU-1');
});

it('normaliza url y sku vacíos a null', function (): void {
    $product = ProductThumbnail::of('Taladro', 1, '   ', '   ');

    expect($product->imageUrl)->toBeNull()
        ->and($product->sku)->toBeNull();
});

it('acepta un producto sin imagen ni sku', function (): void {
    $product = ProductThumbnail::of('Taladro', 1);

    expect($product->imageUrl)->toBeNull()
        ->and($product->sku)->toBeNull();
});

it('rechaza un producto sin nombre', function (): void {
    expect(fn () => ProductThumbnail::of('   ', 1))
        ->toThrow(NotificationsException::class, 'necesita un nombre');
});

it('rechaza una cantidad menor a uno', function (): void {
    expect(fn () => ProductThumbnail::of('Taladro', 0))
        ->toThrow(NotificationsException::class, 'al menos 1');
});

it('expone su forma primitiva', function (): void {
    expect(ProductThumbnail::of('Taladro', 3, 'https://img/1.png', 'SKU-1')->toArray())->toBe([
        'name' => 'Taladro',
        'qty' => 3,
        'imageUrl' => 'https://img/1.png',
        'sku' => 'SKU-1',
    ]);
});
