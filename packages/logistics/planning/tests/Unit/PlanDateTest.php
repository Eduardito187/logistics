<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;

it('builds from a Y-m-d string', function (): void {
    $date = PlanDate::fromString('  2026-10-13  ');

    expect($date->toString())->toBe('2026-10-13')
        ->and((string) $date)->toBe('2026-10-13');
});

it('rejects an invalid date string', function (): void {
    expect(fn () => PlanDate::fromString('13/10/2026'))
        ->toThrow(PlanningException::class, 'fecha de plan inválida');
});

it('builds from a DateTimeInterface discarding the time', function (): void {
    $date = PlanDate::of(new DateTimeImmutable('2026-10-13T23:59:00+00:00'));

    expect($date->toString())->toBe('2026-10-13');
});

it('compares by calendar day regardless of time', function (): void {
    expect(PlanDate::fromString('2026-10-13')->equals(PlanDate::of(new DateTimeImmutable('2026-10-13T05:00:00+00:00'))))->toBeTrue()
        ->and(PlanDate::fromString('2026-10-13')->equals(PlanDate::fromString('2026-10-14')))->toBeFalse();
});
