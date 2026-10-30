<?php

declare(strict_types=1);

use Commerce\Catalog\Domain\Event\CategoryCreated;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\Slug;

it('create graba CategoryCreated con slug normalizado', function (): void {
    $category = Category::create(CategoryId::generate(), 'Electrodomésticos', Slug::of('Electrodomésticos'));

    $events = $category->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CategoryCreated::class);
    expect($category->slug()->value)->toBe('electrodom-sticos');
});

it('rechaza que una categoría sea su propia padre', function (): void {
    $id = CategoryId::generate();
    expect(fn () => Category::create($id, 'X', Slug::of('x'), $id))
        ->toThrow(CatalogException::class);
});

it('rechaza nombre vacío', function (): void {
    expect(fn () => Category::create(CategoryId::generate(), '  ', Slug::of('x')))
        ->toThrow(CatalogException::class);
});
