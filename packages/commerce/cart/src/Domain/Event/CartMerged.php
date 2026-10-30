<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se fusionó un carrito de invitado dentro de éste (típicamente al iniciar sesión): las líneas del
 * carrito origen se sumaron por SKU. El payload identifica el carrito origen consumido.
 */
final readonly class CartMerged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $sourceCartId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'cart.merged';
    }

    public function payload(): array
    {
        return [
            'source_cart_id' => $this->sourceCartId,
        ];
    }
}
