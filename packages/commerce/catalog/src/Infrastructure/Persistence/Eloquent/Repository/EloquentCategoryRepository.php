<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Repository;

use Commerce\Catalog\Domain\Contract\CategoryRepository;
use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Mapper\CategoryMapper;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;

/**
 * Implementación Eloquent del repositorio de categorías. `save` es un upsert por id.
 */
final readonly class EloquentCategoryRepository implements CategoryRepository
{
    public function __construct(
        private CategoryMapper $mapper = new CategoryMapper,
    ) {}

    public function nextIdentity(): CategoryId
    {
        return CategoryId::generate();
    }

    public function save(Category $category): void
    {
        $row = $this->mapper->toRow($category);
        CategoryRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(CategoryId $id): ?Category
    {
        $record = CategoryRecord::query()->find($id->toString());

        return $record instanceof CategoryRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(CategoryRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (CategoryRecord $record): Category => $this->mapper->toDomain($record))
            ->all());
    }
}
