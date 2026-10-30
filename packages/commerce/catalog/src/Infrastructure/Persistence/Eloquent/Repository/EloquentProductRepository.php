<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Catalog\Domain\Contract\ProductRepository;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Domain\ValueObject\Sku;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Mapper\ProductMapper;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\ProductRecord;

/**
 * Implementación Eloquent del repositorio de productos. `save` es un upsert por id (variantes y medios
 * viajan en la misma fila como JSONB).
 */
final readonly class EloquentProductRepository implements ProductRepository
{
    public function __construct(
        private ProductMapper $mapper = new ProductMapper,
    ) {}

    public function nextIdentity(): ProductId
    {
        return ProductId::generate();
    }

    public function save(Product $product): void
    {
        $row = $this->mapper->toRow($product);
        ProductRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(ProductId $id): ?Product
    {
        $record = ProductRecord::query()->find($id->toString());

        return $record instanceof ProductRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findByCanonicalSku(Sku $sku): ?Product
    {
        $record = ProductRecord::query()->where('canonical_sku', $sku->value)->first();

        return $record instanceof ProductRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(ProductRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ProductRecord $record): Product => $this->mapper->toDomain($record))
            ->all());
    }

    public function listByStatus(ProductStatus $status): array
    {
        return array_values(ProductRecord::query()
            ->where('status', $status->value)
            ->orderBy('name')
            ->get()
            ->map(fn (ProductRecord $record): Product => $this->mapper->toDomain($record))
            ->all());
    }
}
