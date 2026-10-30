<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se archivó una lista de precios: se retira; no se le fijan más precios ni resuelve.
 */
final readonly class PriceListArchived extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'pricing.price_list_archived';
    }

    public function payload(): array
    {
        return [];
    }
}
