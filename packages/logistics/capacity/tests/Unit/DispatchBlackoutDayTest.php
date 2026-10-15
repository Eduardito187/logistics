<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\Event\DispatchDayBlocked;
use Logistics\Capacity\Domain\Event\DispatchDayUnblocked;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

it('blocks a global day and records the event', function (): void {
    $day = DispatchBlackoutDay::block(
        DispatchBlackoutDayId::generate(),
        SlotDate::fromString('2026-12-25'),
        null,
        'Christmas',
    );

    $events = $day->releaseEvents();

    expect($day->isGlobal())->toBeTrue()
        ->and($day->municipalityId())->toBeNull()
        ->and($day->reason())->toBe('Christmas')
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DispatchDayBlocked::class)
        ->and($events[0]->eventName())->toBe('capacity.dispatch_day_blocked')
        ->and($events[0]->payload()['municipality_id'])->toBeNull();
});

it('blocks a municipality-specific day', function (): void {
    $day = DispatchBlackoutDay::block(
        DispatchBlackoutDayId::generate(),
        SlotDate::fromString('2026-11-02'),
        '11111111-1111-7111-8111-111111111111',
        'Local festival',
    );

    expect($day->isGlobal())->toBeFalse()
        ->and($day->municipalityId())->toBe('11111111-1111-7111-8111-111111111111');
});

it('rejects an empty reason', function (): void {
    expect(fn () => DispatchBlackoutDay::block(
        DispatchBlackoutDayId::generate(),
        SlotDate::fromString('2026-11-02'),
        null,
        '   ',
    ))->toThrow(CapacityException::class, 'motivo');
});

it('records an unblock event when lifted', function (): void {
    $day = DispatchBlackoutDay::fromState(
        DispatchBlackoutDayId::generate(),
        SlotDate::fromString('2026-12-25'),
        null,
        'Christmas',
        null,
    );

    $day->unblock();
    $events = $day->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DispatchDayUnblocked::class)
        ->and($events[0]->eventName())->toBe('capacity.dispatch_day_unblocked');
});
