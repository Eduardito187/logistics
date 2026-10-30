<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se creó un producto nuevo en estado borrador (aún no visible en el storefront).
 */
final readonly class ProductDrafted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $sellerId,
        public string $name,
        public string $canonicalSku,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'catalog.product_drafted';
    }

    public function payload(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'name' => $this->name,
            'canonical_sku' => $this->canonicalSku,
        ];
    }
}
