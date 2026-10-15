<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se eliminó un precio de combustible del registro.
 */
final readonly class FuelPriceDeleted extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'fleetops.fuel_price_deleted';
    }

    public function payload(): array
    {
        return [];
    }
}
