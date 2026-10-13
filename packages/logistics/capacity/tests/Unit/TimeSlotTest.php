<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

it('accepts a well-formed slot and exposes start/end', function (): void {
    $slot = TimeSlot::fromString('09:00-13:00');

    expect($slot->start)->toBe('09:00')
        ->and($slot->end)->toBe('13:00')
        ->and($slot->toString())->toBe('09:00-13:00');
});

it('trims surrounding whitespace', function (): void {
    expect(TimeSlot::fromString('  14:00-18:00  ')->toString())->toBe('14:00-18:00');
});

it('compares two slots by value', function (): void {
    expect(TimeSlot::fromString('09:00-13:00')->equals(TimeSlot::fromString('09:00-13:00')))->toBeTrue()
        ->and(TimeSlot::fromString('09:00-13:00')->equals(TimeSlot::fromString('09:00-14:00')))->toBeFalse();
});

it('rejects a slot whose start is not before its end', function (): void {
    expect(fn () => TimeSlot::fromString('13:00-09:00'))->toThrow(CapacityException::class, 'antes de terminar');
    expect(fn () => TimeSlot::fromString('09:00-09:00'))->toThrow(CapacityException::class, 'antes de terminar');
});

it('rejects malformed slots', function (string $value): void {
    expect(fn () => TimeSlot::fromString($value))->toThrow(CapacityException::class, 'formato inválido');
})->with([
    'no dash' => ['0900-1300'],
    'single time' => ['09:00'],
    'bad hour' => ['24:00-25:00'],
    'bad minute' => ['09:60-10:00'],
    'non numeric' => ['ab:cd-ef:gh'],
]);
