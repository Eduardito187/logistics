<?php

declare(strict_types=1);

use Commerce\Search\Application\Query\SearchProducts\SearchProducts;
use Commerce\Search\Application\Query\SearchProducts\SearchProductsHandler;
use Commerce\Search\Application\ReadModel\SearchPage;
use Commerce\Search\Infrastructure\Index\ProductSearchIndexer;
use Commerce\Search\Infrastructure\Read\DatabaseProductSearchReader;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Búsqueda sobre la proyección propia `commerce_product_search` con Postgres Full-Text Search y paginación
 * por cursor, en logistics_test. Se siembran productos en `commerce_products` (tabla read-only de catalog,
 * con DB::table, sin depender de catalog por composer) y luego se reconstruye la proyección con el indexador
 * (`reindexAll`), tal como lo hace el comando `commerce:search:reindex`. Skip sin PostgreSQL. El handler se
 * instancia a mano con su reader: el contexto no necesita estar registrado para probar la lectura.
 */
beforeEach(function (): void {
    $this->categoryA = Uuid::generate()->value;
    $this->categoryB = Uuid::generate()->value;

    DB::table('commerce_products')->insert([
        productSearchRow('Heladera Samsung No Frost', 'Refrigerador eficiente de gran capacidad', 'SKU-HELADERA-1', $this->categoryA, 'published'),
        productSearchRow('Televisor LED Samsung 55', 'Smart TV 4K con HDR', 'SKU-TV-55', $this->categoryA, 'published'),
        productSearchRow('Licuadora Oster', 'Potente licuadora de vaso de vidrio', 'SKU-LIC-1', $this->categoryB, 'published'),
        // Borrador: no debe aparecer en búsquedas por defecto (status published).
        productSearchRow('Secadora Samsung oculta', 'Borrador no publicado', 'SKU-SEC-1', $this->categoryA, 'draft'),
    ]);

    // Poblar la proyección de búsqueda desde el catálogo (lo que hace el reindex en operación).
    (new ProductSearchIndexer)->reindexAll();

    $this->handler = new SearchProductsHandler(new DatabaseProductSearchReader);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function productSearchRow(string $name, string $description, string $sku, string $categoryId, string $status): array
{
    return [
        'id' => Uuid::generate()->value,
        'seller_id' => null,
        'category_id' => $categoryId,
        'name' => $name,
        'description' => $description,
        'canonical_sku' => $sku,
        'status' => $status,
        'variants' => '[]',
        'media' => '[]',
    ];
}

it('encuentra por término con FTS en español y respeta el estado published', function (): void {
    /** @var SearchPage $page */
    $page = $this->handler->handle(new SearchProducts(term: 'samsung'));

    // Dos publicados con "Samsung" (heladera y televisor); el borrador "Samsung oculta" queda fuera.
    expect($page->items)->toHaveCount(2)
        ->and($page->total)->toBe(2);

    $skus = array_map(static fn ($item): string => $item->canonicalSku, $page->items);
    expect($skus)->toContain('SKU-HELADERA-1')
        ->and($skus)->toContain('SKU-TV-55')
        ->and($skus)->not->toContain('SKU-SEC-1');
});

it('filtra por categoría', function (): void {
    /** @var SearchPage $page */
    $page = $this->handler->handle(new SearchProducts(categoryId: $this->categoryB));

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->canonicalSku)->toBe('SKU-LIC-1');
});

it('pagina por cursor (keyset) en orden estable por nombre, sin repetir ni saltar', function (): void {
    // 3 publicados ordenados por nombre: Heladera… < Licuadora… < Televisor…
    /** @var SearchPage $first */
    $first = $this->handler->handle(new SearchProducts(limit: 1));
    expect($first->items)->toHaveCount(1)
        ->and($first->items[0]->name)->toBe('Heladera Samsung No Frost')
        ->and($first->nextCursor)->not->toBeNull()
        ->and($first->total)->toBe(3); // total solo en la primera página

    /** @var SearchPage $second */
    $second = $this->handler->handle(new SearchProducts(limit: 1, cursor: $first->nextCursor));
    expect($second->items)->toHaveCount(1)
        ->and($second->items[0]->name)->toBe('Licuadora Oster')
        ->and($second->nextCursor)->not->toBeNull()
        ->and($second->total)->toBeNull(); // sin total en páginas siguientes

    /** @var SearchPage $third */
    $third = $this->handler->handle(new SearchProducts(limit: 1, cursor: $second->nextCursor));
    expect($third->items)->toHaveCount(1)
        ->and($third->items[0]->name)->toBe('Televisor LED Samsung 55')
        ->and($third->nextCursor)->toBeNull(); // última página: no hay más
});

it('degrada a página vacía con término sin coincidencias', function (): void {
    /** @var SearchPage $page */
    $page = $this->handler->handle(new SearchProducts(term: 'inexistentexyz'));

    expect($page->items)->toHaveCount(0)
        ->and($page->nextCursor)->toBeNull();
});
