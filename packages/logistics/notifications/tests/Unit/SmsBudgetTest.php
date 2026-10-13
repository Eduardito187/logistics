<?php

declare(strict_types=1);

use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Notifications\Domain\ValueObject\SmsBudget;

it('reports remaining budget and whether it can send', function (): void {
    $budget = SmsBudget::of('2026-10', 3, 10);

    expect($budget->remaining())->toBe(7)
        ->and($budget->canSend())->toBeTrue();
});

it('cannot send once the cap is reached', function (): void {
    $budget = SmsBudget::of('2026-10', 10, 10);

    expect($budget->canSend())->toBeFalse()
        ->and($budget->remaining())->toBe(0);
});

it('a zero cap disables SMS', function (): void {
    expect(SmsBudget::of('2026-10', 0, 0)->canSend())->toBeFalse();
});

it('rejects a malformed period', function (): void {
    expect(fn () => SmsBudget::of('2026/10', 0, 10))
        ->toThrow(NotificationsException::class, 'YYYY-MM');
});

it('never reports negative remaining', function (): void {
    expect(SmsBudget::of('2026-10', 15, 10)->remaining())->toBe(0);
});
