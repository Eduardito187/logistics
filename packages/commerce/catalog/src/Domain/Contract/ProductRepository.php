<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Contract;

use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\ProductStatus;
use Commerce\Catalog\Domain\ValueObject\Sku;

/**
 * Puerto de persistencia de productos. La implementación vive en Infrastructure.
 */
interface ProductRepository
{
    public function nextIdentity(): ProductId;

    /**
     * Upsert del producto por id (incluye sus variantes y medios).
     */
    public function save(Product $product): void;

    public function findById(ProductId $id): ?Product;

    public function findByCanonicalSku(Sku $sku): ?Product;

    /**
     * @return list<Product>
     */
    public function all(): array;

    /**
     * @return list<Product>
     */
    public function listByStatus(ProductStatus $status): array;
}
