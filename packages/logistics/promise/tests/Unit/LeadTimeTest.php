<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\LeadTime;

it('none is zero minutes', function (): void {
    $lead = LeadTime::none();

    expect($lead->minutes)->toBe(0)
        ->and($lead->isZero())->toBeTrue();
});

it('builds a lead time from minutes', function (): void {
    $lead = LeadTime::ofMinutes(90);

    expect($lead->minutes)->toBe(90)
        ->and($lead->isZero())->toBeFalse();
});

it('converts days to minutes (1 day = 1440 minutes)', function (): void {
    expect(LeadTime::ofDays(2)->minutes)->toBe(2880)
        ->and(LeadTime::ofDays(0)->minutes)->toBe(0)
        ->and(LeadTime::ofDays(0)->isZero())->toBeTrue();
});

it('rejects a negative lead in minutes', function (): void {
    expect(fn (): LeadTime => LeadTime::ofMinutes(-1))->toThrow(PromiseException::class);
});

it('rejects a negative lead in days', function (): void {
    expect(fn (): LeadTime => LeadTime::ofDays(-1))->toThrow(PromiseException::class);
});
