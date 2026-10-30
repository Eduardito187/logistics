<?php

declare(strict_types=1);

use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\ValueObject\MediaAsset;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Commerce\Catalog\Domain\ValueObject\Slug;

it('Sku normaliza a mayúsculas sin espacios y rechaza vacío', function (): void {
    $sku = Sku::of('  bel-tv-55 ');
    expect($sku->value)->toBe('BEL-TV-55')
        ->and($sku->equals(Sku::of('bel-tv-55')))->toBeTrue();

    expect(fn () => Sku::of('   '))->toThrow(CatalogException::class);
});

it('Slug normaliza a minúsculas con guiones y rechaza vacío', function (): void {
    expect(Slug::of('  Electro Domésticos!! ')->value)->toBe('electro-dom-sticos');
    expect(Slug::of('Tecnología')->value)->toBe('tecnolog-a');

    expect(fn () => Slug::of('  ---  '))->toThrow(CatalogException::class);
});

it('ProductVariant hace round-trip por array y exige nombre', function (): void {
    $variant = ProductVariant::of(Sku::of('tv-55-neg'), 'Negro', ['color' => 'negro']);
    expect($variant->toArray())->toBe([
        'sku' => 'TV-55-NEG',
        'name' => 'Negro',
        'attributes' => ['color' => 'negro'],
    ]);

    $again = ProductVariant::fromArray($variant->toArray());
    expect($again->sku->value)->toBe('TV-55-NEG')
        ->and($again->attributes)->toBe(['color' => 'negro']);

    expect(fn () => ProductVariant::of(Sku::of('x-1'), '  '))->toThrow(CatalogException::class);
});

it('MediaAsset exige ruta y posición no negativa', function (): void {
    $media = MediaAsset::of('products/tv-55.jpg', 'TV 55', 1);
    expect($media->toArray())->toBe(['path' => 'products/tv-55.jpg', 'alt' => 'TV 55', 'position' => 1]);

    expect(fn () => MediaAsset::of('   '))->toThrow(CatalogException::class);
    expect(fn () => MediaAsset::of('a.jpg', '', -1))->toThrow(CatalogException::class);
});
