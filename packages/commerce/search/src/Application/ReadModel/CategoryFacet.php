<?php

declare(strict_types=1);

namespace Commerce\Search\Application\ReadModel;

/**
 * Faceta de navegación: cantidad de productos en una categoría. `categoryId` null agrupa los productos
 * sin categoría. Forma primitiva.
 */
final readonly class CategoryFacet
{
    public function __construct(
        public ?string $categoryId,
        public int $count,
    ) {}

    /**
     * Construye la faceta desde una fila cruda del `groupBy` (stdClass de DB::table).
     */
    public static function fromRow(object $row): self
    {
        $categoryId = $row->category_id ?? null;
        $count = $row->total ?? null;

        return new self(
            categoryId: is_scalar($categoryId) ? (string) $categoryId : null,
            count: is_numeric($count) ? (int) $count : 0,
        );
    }

    /**
     * @return array{category_id: ?string, count: int}
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'count' => $this->count,
        ];
    }
}
