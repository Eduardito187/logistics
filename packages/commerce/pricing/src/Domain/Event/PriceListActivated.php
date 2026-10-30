<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó una lista de precios: queda vigente y resuelve precios al público para su canal.
 */
final readonly class PriceListActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'pricing.price_list_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
