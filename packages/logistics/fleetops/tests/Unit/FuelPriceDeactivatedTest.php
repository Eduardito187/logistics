<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceDeactivated;

it('expone nombre y carga vacía del evento de desactivación de precio', function (): void {
    $event = new FuelPriceDeactivated('price-2');

    expect($event->eventName())->toBe('fleetops.fuel_price_deactivated')
        ->and($event->payload())->toBe([])
        ->and($event->aggregateId())->toBe('price-2');
});
