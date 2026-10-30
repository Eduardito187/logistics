<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se publicó un producto: queda visible y comprable. El payload lleva una instantánea mínima para que
 * los consumidores aguas abajo (búsqueda/indexado) proyecten sin volver a consultar el catálogo.
 */
final readonly class ProductPublished extends BaseDomainEvent
{
    /**
     * @param list<string> $variantSkus
     */
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $canonicalSku,
        public ?string $categoryId,
        public array $variantSkus,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'catalog.product_published';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'canonical_sku' => $this->canonicalSku,
            'category_id' => $this->categoryId,
            'variant_skus' => $this->variantSkus,
        ];
    }
}
