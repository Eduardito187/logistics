<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\FuelPriceUpdated;

it('expone nombre y carga del evento de edición de precio', function (): void {
    $event = new FuelPriceUpdated('price-1', 'diesel', 372, 'BOB', '2026-10-01', 'ANH');

    expect($event->eventName())->toBe('fleetops.fuel_price_updated')
        ->and($event->aggregateId())->toBe('price-1')
        ->and($event->payload())->toBe([
            'fuel_type' => 'diesel',
            'price_cents' => 372,
            'currency' => 'BOB',
            'effective_from' => '2026-10-01',
            'source' => 'ANH',
        ]);
});

it('conserva la fuente nula en la carga', function (): void {
    $event = new FuelPriceUpdated('price-2', 'gnv', 200, 'BOB', '2026-10-01', null);

    expect($event->source)->toBeNull()
        ->and($event->payload()['source'])->toBeNull();
});
