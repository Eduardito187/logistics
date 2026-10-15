<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\ActiveHorizon;
use Logistics\Capacity\Domain\Event\CapacityWindowActivated;
use Logistics\Capacity\Domain\ValueObject\ActiveHorizonId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

it('starts a horizon and records the activation event', function (): void {
    $horizon = ActiveHorizon::start(
        ActiveHorizonId::generate(),
        '11111111-1111-7111-8111-111111111111',
        SlotDate::fromString('2026-11-30'),
    );

    $events = $horizon->releaseEvents();

    expect($horizon->activeUntil()->toString())->toBe('2026-11-30')
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CapacityWindowActivated::class)
        ->and($events[0]->eventName())->toBe('capacity.capacity_window_activated');
});

it('only moves the horizon forward', function (): void {
    $horizon = ActiveHorizon::fromState(
        ActiveHorizonId::generate(),
        null,
        SlotDate::fromString('2026-11-30'),
    );

    $advanced = $horizon->extendTo(SlotDate::fromString('2026-12-31'));
    expect($advanced)->toBeTrue()
        ->and($horizon->activeUntil()->toString())->toBe('2026-12-31')
        ->and($horizon->releaseEvents())->toHaveCount(1);

    // Un rango que termina antes no retrocede el horizonte ni emite evento.
    $notAdvanced = $horizon->extendTo(SlotDate::fromString('2026-11-15'));
    expect($notAdvanced)->toBeFalse()
        ->and($horizon->activeUntil()->toString())->toBe('2026-12-31')
        ->and($horizon->releaseEvents())->toHaveCount(0);
});

it('knows which dates it covers', function (): void {
    $horizon = ActiveHorizon::fromState(
        ActiveHorizonId::generate(),
        null,
        SlotDate::fromString('2026-11-30'),
    );

    expect($horizon->covers(SlotDate::fromString('2026-11-30')))->toBeTrue()
        ->and($horizon->covers(SlotDate::fromString('2026-11-15')))->toBeTrue()
        ->and($horizon->covers(SlotDate::fromString('2026-12-01')))->toBeFalse();
});
