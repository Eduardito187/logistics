<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Arrancó el fulfillment de la orden: se creó el envío en la logística aguas abajo (el envío se liga
 * por `externalId` = id de la orden, fuente `commerce`).
 */
final readonly class OrderFulfillmentStarted extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'orders.fulfillment_started';
    }

    public function payload(): array
    {
        return [];
    }
}
