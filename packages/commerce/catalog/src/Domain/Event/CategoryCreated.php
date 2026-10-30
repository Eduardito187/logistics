<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se creó una categoría del catálogo (opcionalmente bajo una categoría padre).
 */
final readonly class CategoryCreated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $slug,
        public ?string $parentId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'catalog.category_created';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parentId,
        ];
    }
}
