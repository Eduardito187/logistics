<?php

declare(strict_types=1);

use Commerce\Search\Domain\Exception\SearchException;
use Commerce\Search\Domain\ValueObject\SearchCriteria;

/**
 * Reglas del VO de criterio de búsqueda (dominio puro, sin Laravel): defaults, normalización y validación
 * de límite (1..100) y estado permitido.
 */
it('aplica los valores por defecto (status published, limit 20, sin term/categoría/cursor)', function (): void {
    $criteria = SearchCriteria::of();

    expect($criteria->status)->toBe('published')
        ->and($criteria->limit)->toBe(20)
        ->and($criteria->term)->toBeNull()
        ->and($criteria->categoryId)->toBeNull()
        ->and($criteria->cursor)->toBeNull()
        ->and($criteria->hasTerm())->toBeFalse();
});

it('normaliza term/categoría/cursor vacíos o con espacios a null', function (): void {
    $criteria = SearchCriteria::of(term: '   ', categoryId: '', cursor: null);

    expect($criteria->term)->toBeNull()
        ->and($criteria->categoryId)->toBeNull()
        ->and($criteria->cursor)->toBeNull();

    $trimmed = SearchCriteria::of(term: '  heladera  ');
    expect($trimmed->term)->toBe('heladera')
        ->and($trimmed->hasTerm())->toBeTrue();
});

it('acepta los tres estados permitidos', function (string $status): void {
    expect(SearchCriteria::of(status: $status)->status)->toBe($status);
})->with(['draft', 'published', 'archived']);

it('rechaza un estado no permitido', function (): void {
    SearchCriteria::of(status: 'unknown');
})->throws(SearchException::class);

it('rechaza un límite por debajo del mínimo', function (): void {
    SearchCriteria::of(limit: 0);
})->throws(SearchException::class);

it('rechaza un límite por encima del máximo', function (): void {
    SearchCriteria::of(limit: 101);
})->throws(SearchException::class);

it('acepta los límites de borde 1 y 100', function (): void {
    expect(SearchCriteria::of(limit: 1)->limit)->toBe(1)
        ->and(SearchCriteria::of(limit: 100)->limit)->toBe(100);
});
