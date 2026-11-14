<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Exception\PlanningException;

it('builds a route stop, trimming the shipment id', function (): void {
    $stop = RouteStop::of('  shp-1  ', -16.5, -68.15);

    expect($stop->shipmentId)->toBe('shp-1')
        ->and($stop->latitude)->toBe(-16.5)
        ->and($stop->longitude)->toBe(-68.15);
});

it('rejects an empty shipment id', function (): void {
    expect(fn () => RouteStop::of('   ', -16.5, -68.15))
        ->toThrow(PlanningException::class, 'necesita un id de envío');
});

it('rejects a latitude out of range', function (): void {
    expect(fn () => RouteStop::of('shp-1', 91.0, -68.15))
        ->toThrow(PlanningException::class, 'latitud fuera de rango');
});

it('rejects a longitude out of range', function (): void {
    expect(fn () => RouteStop::of('shp-1', -16.5, -181.0))
        ->toThrow(PlanningException::class, 'longitud fuera de rango');
});
