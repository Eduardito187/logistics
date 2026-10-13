<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió (o actualizó) el precio por litro de un tipo de combustible, vigente desde una fecha.
 */
final readonly class FuelPriceSet extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $fuelType,
        public int $priceCents,
        public string $currency,
        public string $effectiveFrom,
        public ?string $source,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.fuel_price_set';
    }

    public function payload(): array
    {
        return [
            'fuel_type' => $this->fuelType,
            'price_cents' => $this->priceCents,
            'currency' => $this->currency,
            'effective_from' => $this->effectiveFrom,
            'source' => $this->source,
        ];
    }
}
