<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se canceló la orden (solo era posible mientras estaba pendiente de pago).
 */
final readonly class OrderCancelled extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'orders.order_cancelled';
    }

    public function payload(): array
    {
        return [];
    }
}
