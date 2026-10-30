<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se creó una lista de precios nueva (arranca en estado Draft).
 */
final readonly class PriceListCreated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $currency,
        public string $channel,
        public string $status,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'pricing.price_list_created';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'currency' => $this->currency,
            'channel' => $this->channel,
            'status' => $this->status,
        ];
    }
}
