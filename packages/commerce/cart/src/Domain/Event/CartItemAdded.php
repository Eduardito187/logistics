<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agregó (o se incrementó la cantidad de) una línea al carrito. El payload lleva la cantidad que se
 * sumó en esta operación, no el total acumulado de la línea.
 */
final readonly class CartItemAdded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $sku,
        public string $productName,
        public int $unitPriceCents,
        public int $qty,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'cart.item_added';
    }

    public function payload(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'unit_price_cents' => $this->unitPriceCents,
            'qty' => $this->qty,
        ];
    }
}
