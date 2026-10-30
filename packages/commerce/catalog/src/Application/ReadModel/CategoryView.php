<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\ReadModel;

use Commerce\Catalog\Domain\Model\Category;

/**
 * Proyección de lectura de una categoría. Primitivos: no expone objetos de dominio.
 */
final readonly class CategoryView
{
    private function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $parentId,
    ) {}

    public static function fromDomain(Category $category): self
    {
        return new self(
            $category->id()->toString(),
            $category->name(),
            $category->slug()->value,
            $category->parentId()?->toString(),
        );
    }

    /**
     * @return array{id: string, name: string, slug: string, parent_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parentId,
        ];
    }
}
