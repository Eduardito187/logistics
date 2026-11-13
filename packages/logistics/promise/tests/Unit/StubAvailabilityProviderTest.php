<?php

declare(strict_types=1);

use Logistics\Promise\Infrastructure\Availability\StubAvailabilityProvider;

/*
| Stub determinista del puerto de disponibilidad. Prueban que ofrece dos franjas fijas (AM/PM) para
| hoy y fechas futuras, y ninguna para fechas pasadas, normalizando la fecha a medianoche.
*/

it('ofrece las franjas AM y PM para una fecha futura', function (): void {
    $provider = new StubAvailabilityProvider;
    $future = (new DateTimeImmutable('today'))->modify('+3 days');

    $slots = $provider->availableSlots('zone-1', $future);

    expect($slots)->toHaveCount(2)
        ->and($slots[0]->timeSlot)->toBe('AM')
        ->and($slots[1]->timeSlot)->toBe('PM')
        ->and($slots[0]->dateString())->toBe($future->format('Y-m-d'));
});

it('ofrece franjas para hoy mismo', function (): void {
    $provider = new StubAvailabilityProvider;

    $slots = $provider->availableSlots('zone-1', new DateTimeImmutable('today'));

    expect($slots)->toHaveCount(2);
});

it('no ofrece franjas para una fecha pasada', function (): void {
    $provider = new StubAvailabilityProvider;
    $past = (new DateTimeImmutable('today'))->modify('-1 day');

    expect($provider->availableSlots('zone-1', $past))->toBe([]);
});
