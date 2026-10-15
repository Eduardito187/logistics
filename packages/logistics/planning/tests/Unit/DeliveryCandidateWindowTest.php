<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;

/**
 * Candidato de prueba con una ubicación fija en La Paz; lo que varía es la ventana horaria.
 */
function candidateWithWindow(?string $start, ?string $end): DeliveryCandidate
{
    return DeliveryCandidate::of(
        shipmentId: 'shp-1',
        latitude: -16.5,
        longitude: -68.15,
        weightKg: 10.0,
        volumeM3: 0.1,
        departmentId: 'LP',
        zoneKey: 'z1',
        deliverySlot: null,
        orderReference: null,
        customerName: null,
        address: null,
        windowStart: $start,
        windowEnd: $end,
    );
}

it('keeps no window when neither bound is given', function (): void {
    $candidate = candidateWithWindow(null, null);

    expect($candidate->hasDeliveryWindow())->toBeFalse()
        ->and($candidate->windowStart)->toBeNull()
        ->and($candidate->windowEnd)->toBeNull()
        ->and($candidate->deliveryWindow(new DateTimeImmutable('2026-10-13T08:00:00+00:00')))->toBeNull();
});

it('discards a half window (only one bound given)', function (): void {
    expect(candidateWithWindow('09:00', null)->hasDeliveryWindow())->toBeFalse()
        ->and(candidateWithWindow(null, '11:00')->hasDeliveryWindow())->toBeFalse();
});

it('rejects an invalid time format', function (): void {
    expect(fn () => candidateWithWindow('9am', '11:00'))->toThrow(PlanningException::class);
});

it('rejects a window that does not start before it ends', function (): void {
    expect(fn () => candidateWithWindow('11:00', '09:00'))->toThrow(PlanningException::class)
        ->and(fn () => candidateWithWindow('09:00', '09:00'))->toThrow(PlanningException::class);
});

it('converts the clock window to seconds from departure', function (): void {
    $candidate = candidateWithWindow('09:00', '11:00');
    $departAt = new DateTimeImmutable('2026-10-13T08:00:00+00:00');

    $window = $candidate->deliveryWindow($departAt);

    expect($candidate->hasDeliveryWindow())->toBeTrue()
        ->and($window)->not->toBeNull()
        // 09:00 abre 1 h (3600 s) después de salir a las 08:00; 11:00 cierra 3 h (10800 s) después.
        ->and($window?->earliestSeconds)->toBe(3600.0)
        ->and($window?->latestSeconds)->toBe(10800.0);
});

it('clamps the opening to zero when the window already opened at departure', function (): void {
    $candidate = candidateWithWindow('07:00', '09:00');
    $departAt = new DateTimeImmutable('2026-10-13T08:00:00+00:00');

    $window = $candidate->deliveryWindow($departAt);

    // 07:00 ya pasó a la salida (08:00): la apertura se recorta a 0; el cierre son 3600 s (09:00).
    expect($window?->earliestSeconds)->toBe(0.0)
        ->and($window?->latestSeconds)->toBe(3600.0);
});

it('collapses an already-expired window to an instantaneous one at departure', function (): void {
    $candidate = candidateWithWindow('06:00', '07:00');
    $departAt = new DateTimeImmutable('2026-10-13T08:00:00+00:00');

    $window = $candidate->deliveryWindow($departAt);

    // Ambos extremos ya pasaron: apertura recortada a 0 y cierre llevado, como mínimo, a la apertura.
    expect($window?->earliestSeconds)->toBe(0.0)
        ->and($window?->latestSeconds)->toBe(0.0);
});
