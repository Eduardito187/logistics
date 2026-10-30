<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Contract;

use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;

/**
 * Puerto de persistencia de categorías. La implementación vive en Infrastructure.
 */
interface CategoryRepository
{
    public function nextIdentity(): CategoryId;

    public function save(Category $category): void;

    public function findById(CategoryId $id): ?Category;

    /**
     * @return list<Category>
     */
    public function all(): array;
}
