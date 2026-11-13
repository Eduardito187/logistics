<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;

it('builds a candidate, trimming the id and defaulting zone, slot and requirement', function (): void {
    $candidate = DeliveryCandidate::of('  shp-1  ', -16.5, -68.15, 10.0, 0.1, 'LP');

    expect($candidate->shipmentId)->toBe('shp-1')
        ->and($candidate->departmentId)->toBe('LP')
        // Sin zona explícita, la zona se deriva del departamento.
        ->and($candidate->zoneKey)->toBe('LP')
        ->and($candidate->slotKey())->toBe('unscheduled')
        ->and($candidate->departmentKey())->toBe('LP')
        ->and($candidate->requirement)->toBeInstanceOf(ShipmentRouteRequirement::class)
        ->and($candidate->requirement->isEmpty())->toBeTrue()
        ->and($candidate->loadEstimated)->toBeFalse();
});

it('falls back to unknown when neither zone nor department are given', function (): void {
    $candidate = DeliveryCandidate::of('shp-1', -16.5, -68.15, 10.0, 0.1);

    expect($candidate->zoneKey)->toBe('unknown')
        ->and($candidate->departmentKey())->toBe('unknown');
});

it('derives department and slot keys from their resolved values', function (): void {
    $candidate = DeliveryCandidate::of('shp-1', -16.5, -68.15, 10.0, 0.1, null, '  z1  ', '  morning  ');

    expect($candidate->zoneKey)->toBe('z1')
        ->and($candidate->deliverySlot)->toBe('morning')
        ->and($candidate->slotKey())->toBe('morning')
        ->and($candidate->departmentKey())->toBe('unknown');
});

it('rejects an empty shipment id', function (): void {
    expect(fn () => DeliveryCandidate::of('   ', -16.5, -68.15, 10.0, 0.1))
        ->toThrow(PlanningException::class, 'necesita un id de envío');
});

it('rejects coordinates out of range', function (): void {
    expect(fn () => DeliveryCandidate::of('shp-1', -91.0, -68.15, 10.0, 0.1))
        ->toThrow(PlanningException::class, 'latitud fuera de rango')
        ->and(fn () => DeliveryCandidate::of('shp-1', -16.5, 181.0, 10.0, 0.1))
        ->toThrow(PlanningException::class, 'longitud fuera de rango');
});

it('rejects a negative weight or volume', function (): void {
    expect(fn () => DeliveryCandidate::of('shp-1', -16.5, -68.15, -1.0, 0.1))
        ->toThrow(PlanningException::class, 'peso inválido')
        ->and(fn () => DeliveryCandidate::of('shp-1', -16.5, -68.15, 10.0, -0.1))
        ->toThrow(PlanningException::class, 'volumen inválido');
});

it('discards a blank half window without failing', function (): void {
    $candidate = DeliveryCandidate::of('shp-1', -16.5, -68.15, 10.0, 0.1, 'LP', null, null, null, null, null, '  ', '11:00');

    expect($candidate->hasDeliveryWindow())->toBeFalse()
        ->and($candidate->windowStart)->toBeNull()
        ->and($candidate->windowEnd)->toBeNull();
});
