<?php

declare(strict_types=1);

use Commerce\Catalog\Domain\Event\ProductArchived;
use Commerce\Catalog\Domain\Event\ProductDrafted;
use Commerce\Catalog\Domain\Event\ProductPublished;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Domain\ValueObject\ProductVariant;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Logistics\Shared\Domain\ValueObject\Uuid;

function catalogDraftProduct(): Product
{
    return Product::draft(
        ProductId::generate(),
        Uuid::generate(),
        'Televisor LED 55"',
        Sku::of('SKU-TV-55'),
        'Un televisor.',
    );
}

it('draft crea un producto en borrador y graba ProductDrafted', function (): void {
    $product = catalogDraftProduct();

    expect($product->status())->toBe(ProductStatus::Draft);

    $events = $product->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProductDrafted::class);
    expect($events[0]->payload()['canonical_sku'])->toBe('SKU-TV-55');
});

it('draft rechaza nombre vacío', function (): void {
    expect(fn () => Product::draft(ProductId::generate(), null, '   ', Sku::of('SKU-X')))
        ->toThrow(CatalogException::class);
});

it('publish deja el producto publicado y graba ProductPublished una sola vez', function (): void {
    $product = catalogDraftProduct();
    $product->addVariant(ProductVariant::of(Sku::of('SKU-TV-55-NEG'), 'Negro'));
    $product->releaseEvents();

    $product->publish();
    expect($product->status())->toBe(ProductStatus::Published);

    $events = $product->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProductPublished::class);
    expect($events[0]->payload()['variant_skus'])->toBe(['SKU-TV-55-NEG']);

    // Idempotente: publicar de nuevo no graba otro evento.
    $product->publish();
    expect($product->releaseEvents())->toHaveCount(0);
});

it('archive es idempotente y graba ProductArchived', function (): void {
    $product = catalogDraftProduct();
    $product->releaseEvents();

    $product->archive();
    $events = $product->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProductArchived::class);

    $product->archive();
    expect($product->releaseEvents())->toHaveCount(0);
});

it('no permite dos variantes con el mismo SKU', function (): void {
    $product = catalogDraftProduct();
    $product->addVariant(ProductVariant::of(Sku::of('SKU-DUP'), 'A'));

    expect(fn () => $product->addVariant(ProductVariant::of(Sku::of('sku-dup'), 'B')))
        ->toThrow(CatalogException::class);

    expect($product->variants())->toHaveCount(1);
});
