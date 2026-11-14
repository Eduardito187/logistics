<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceDeleted;

it('expone nombre y carga vacía del evento de eliminación de precio', function (): void {
    $event = new FuelPriceDeleted('price-3');

    expect($event->eventName())->toBe('fleetops.fuel_price_deleted')
        ->and($event->payload())->toBe([])
        ->and($event->aggregateId())->toBe('price-3');
});
