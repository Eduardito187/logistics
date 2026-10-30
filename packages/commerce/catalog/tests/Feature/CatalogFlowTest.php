<?php

declare(strict_types=1);

use Commerce\Catalog\Application\Command\AddProductVariant\AddProductVariant;
use Commerce\Catalog\Application\Command\CreateCategory\CreateCategory;
use Commerce\Catalog\Application\Command\DraftProduct\DraftProduct;
use Commerce\Catalog\Application\Command\PublishProduct\PublishProduct;
use Commerce\Catalog\Application\Query\GetProduct\GetProduct;
use Commerce\Catalog\Application\Query\ListCategories\ListCategories;
use Commerce\Catalog\Application\Query\ListProducts\ListProducts;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Circuito de catálogo contra PostgreSQL real (logistics_test, RefreshDatabase): crear categoría,
 * dar de alta un producto, agregarle una variante, publicarlo y leerlo por las queries. Atraviesa
 * CommandBus → handler → repositorio → mapper → persistencia → QueryBus → ReadModel.
 */
beforeEach(function (): void {
    $this->commands = app(CommandBus::class);
    $this->queries = app(QueryBus::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('crea una categoría y la lista', function (): void {
    $this->commands->dispatch(new CreateCategory('Tecnología', 'tecnologia'));

    $categories = $this->queries->ask(new ListCategories);
    expect($categories)->toHaveCount(1)
        ->and($categories[0]->slug)->toBe('tecnologia');
});

it('da de alta, agrega variante, publica y consulta un producto', function (): void {
    $this->commands->dispatch(new CreateCategory('Tecnología', 'tecnologia'));
    $categoryId = $this->queries->ask(new ListCategories)[0]->id;

    $this->commands->dispatch(new DraftProduct(
        name: 'Televisor LED 55"',
        canonicalSku: 'SKU-TV-55',
        description: 'Un televisor.',
        categoryId: $categoryId,
    ));

    $drafts = $this->queries->ask(new ListProducts('draft'));
    expect($drafts)->toHaveCount(1);
    $productId = $drafts[0]->id;

    $this->commands->dispatch(new AddProductVariant($productId, 'SKU-TV-55-NEG', 'Negro', ['color' => 'negro']));
    $this->commands->dispatch(new PublishProduct($productId));

    $view = $this->queries->ask(new GetProduct($productId));
    expect($view)->not->toBeNull()
        ->and($view->status)->toBe('published')
        ->and($view->canonicalSku)->toBe('SKU-TV-55')
        ->and($view->categoryId)->toBe($categoryId)
        ->and($view->variants)->toHaveCount(1)
        ->and($view->variants[0]['sku'])->toBe('SKU-TV-55-NEG');

    expect($this->queries->ask(new ListProducts('published')))->toHaveCount(1);
    expect($this->queries->ask(new ListProducts('draft')))->toHaveCount(0);
});
