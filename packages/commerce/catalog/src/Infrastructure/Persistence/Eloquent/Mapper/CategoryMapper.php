<?php

declare(strict_types=1);

namespace Commerce\Catalog\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Catalog\Domain\Model\Category;
use Commerce\Catalog\Domain\ValueObject\CategoryId;
use Commerce\Catalog\Domain\ValueObject\PhysicalSpec;
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
            PhysicalSpec::fromArray([
                'weight_kg' => $record->weight_kg,
                'length_cm' => $record->length_cm,
                'width_cm' => $record->width_cm,
                'height_cm' => $record->height_cm,
            ]),
        );
    }

    /**
     * @return array{id: string, name: string, slug: string, parent_id: ?string, weight_kg: ?float, length_cm: ?float, width_cm: ?float, height_cm: ?float}
     */
    public function toRow(Category $category): array
    {
        return [
            'id' => $category->id()->toString(),
            'name' => $category->name(),
            'slug' => $category->slug()->value,
            'parent_id' => $category->parentId()?->toString(),
            'weight_kg' => $category->physicalDefaults()->weightKg,
            'length_cm' => $category->physicalDefaults()->lengthCm,
            'width_cm' => $category->physicalDefaults()->widthCm,
            'height_cm' => $category->physicalDefaults()->heightCm,
        ];
    }
}
