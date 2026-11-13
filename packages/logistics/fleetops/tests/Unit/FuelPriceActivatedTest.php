<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceActivated;

it('expone nombre y carga vacía del evento de activación de precio', function (): void {
    $event = new FuelPriceActivated('price-1');

    expect($event->eventName())->toBe('fleetops.fuel_price_activated')
        ->and($event->payload())->toBe([])
        ->and($event->aggregateId())->toBe('price-1');
});
