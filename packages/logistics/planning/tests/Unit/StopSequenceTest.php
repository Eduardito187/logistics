<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\StopSequence;

it('creates a 1-based sequence', function (): void {
    expect(StopSequence::of(1)->value)->toBe(1)
        ->and(StopSequence::first()->value)->toBe(1)
        ->and(StopSequence::of(7)->value)->toBe(7);
});

it('advances to the next position', function (): void {
    expect(StopSequence::first()->next()->value)->toBe(2)
        ->and(StopSequence::of(5)->next()->value)->toBe(6);
});

it('compares by value', function (): void {
    expect(StopSequence::of(3)->equals(StopSequence::of(3)))->toBeTrue()
        ->and(StopSequence::of(3)->equals(StopSequence::of(4)))->toBeFalse();
});

it('rejects a sequence below 1', function (): void {
    expect(fn () => StopSequence::of(0))
        ->toThrow(PlanningException::class, '>= 1');
});
