<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se desactivó un precio de combustible: deja de considerarse sin borrarlo del histórico.
 */
final readonly class FuelPriceDeactivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'fleetops.fuel_price_deactivated';
    }

    public function payload(): array
    {
        return [];
    }
}
