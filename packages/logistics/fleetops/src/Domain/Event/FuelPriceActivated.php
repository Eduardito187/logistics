<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó un precio de combustible: vuelve a considerarse vigente para consumo/listados.
 */
final readonly class FuelPriceActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'fleetops.fuel_price_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
