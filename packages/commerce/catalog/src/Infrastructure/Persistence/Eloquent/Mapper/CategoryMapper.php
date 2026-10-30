<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\Slug;
use Commerce\Catalog\Infrastructure\Persistence\Eloquent\Model\CategoryRecord;

/**
 * Traduce entre el agregado Category (dominio) y su fila Eloquent.
 */
final class CategoryMapper
{
    public function toDomain(CategoryRecord $record): Category
    {
        return Category::reconstitute(
            CategoryId::fromString($record->id),
            $record->name,
            Slug::of($record->slug),
            $record->parent_id !== null ? CategoryId::fromString($record->parent_id) : null,
        );
    }

    /**
     * @return array{id: string, name: string, slug: string, parent_id: ?string}
     */
    public function toRow(Category $category): array
    {
        return [
            'id' => $category->id()->toString(),
            'name' => $category->name(),
            'slug' => $category->slug()->value,
            'parent_id' => $category->parentId()?->toString(),
        ];
    }
}
