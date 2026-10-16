<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se dio de alta o se actualizó un SKU del catálogo del seller (y su mapeo opcional a material SAP).
 */
final readonly class SellerSkuUpserted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $sku,
        public ?string $sapMaterial,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'sellers.seller_sku_upserted';
    }

    public function payload(): array
    {
        return [
            'sku' => $this->sku,
            'sap_material' => $this->sapMaterial,
        ];
    }
}
