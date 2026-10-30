<?php

declare(strict_types=1);

namespace Commerce\Search\Application\ReadModel;

/**
 * Página de resultados de búsqueda con paginación por cursor (keyset).
 *
 *  - `items`: resultados de esta página.
 *  - `nextCursor`: cursor opaco para pedir la página siguiente (null = no hay más).
 *  - `total`: total de coincidencias cuando se pudo calcular (p.ej. en la primera página); null si no.
 */
final readonly class SearchPage
{
    /**
     * @param list<ProductSearchResult> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?int $total,
    ) {}

    /**
     * @return array{items: list<array{id: string, name: string, canonical_sku: string, category_id: ?string, status: string}>, next_cursor: ?string, total: ?int}
     */
    public function toArray(): array
    {
        return [
            'items' => array_values(array_map(
                static fn (ProductSearchResult $item): array => $item->toArray(),
                $this->items,
            )),
            'next_cursor' => $this->nextCursor,
            'total' => $this->total,
        ];
    }
}
