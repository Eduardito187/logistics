<?php

declare(strict_types=1);

namespace Commerce\Search\Application\ReadModel;

/**
 * Resultado de búsqueda de un producto: proyección mínima para listados/storefront. Forma primitiva,
 * no expone dominio. Se construye desde una fila cruda (stdClass) de `commerce_products` leída read-only.
 */
final readonly class ProductSearchResult
{
    public function __construct(
        public string $id,
        public string $name,
        public string $canonicalSku,
        public ?string $categoryId,
        public string $status,
    ) {}

    /**
     * Construye el resultado desde una fila cruda de `commerce_products` (stdClass de DB::table).
     */
    public static function fromRow(object $row): self
    {
        return new self(
            id: self::str($row, 'id'),
            name: self::str($row, 'name'),
            canonicalSku: self::str($row, 'canonical_sku'),
            categoryId: self::nullableStr($row, 'category_id'),
            status: self::str($row, 'status'),
        );
    }

    /**
     * @return array{id: string, name: string, canonical_sku: string, category_id: ?string, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'canonical_sku' => $this->canonicalSku,
            'category_id' => $this->categoryId,
            'status' => $this->status,
        ];
    }

    private static function str(object $row, string $field): string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(object $row, string $field): ?string
    {
        $value = $row->{$field} ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
