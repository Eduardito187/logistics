<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se creó una orden comercial (checkout), en estado pendiente de pago.
 */
final readonly class OrderPlaced extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public ?string $customerId,
        public string $currency,
        public int $totalCents,
        public int $lineCount,
        public ?string $cartId = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'orders.order_placed';
    }

    public function payload(): array
    {
        return [
            'customer_id' => $this->customerId,
            'currency' => $this->currency,
            'total_cents' => $this->totalCents,
            'line_count' => $this->lineCount,
            'cart_id' => $this->cartId,
        ];
    }
}
