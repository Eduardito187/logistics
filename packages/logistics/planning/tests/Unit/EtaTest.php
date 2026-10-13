<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\Eta;

it('holds an arrival instant and the minutes from departure', function (): void {
    $arrival = new DateTimeImmutable('2026-10-13T08:20:00+00:00');
    $eta = Eta::at($arrival, 50);

    expect($eta->minutesFromDeparture)->toBe(50)
        ->and($eta->arrivalAt->format(DATE_ATOM))->toBe('2026-10-13T08:20:00+00:00');
});

it('allows a zero-minute ETA (the first stop at departure)', function (): void {
    expect(Eta::at(new DateTimeImmutable('2026-10-13T08:00:00+00:00'), 0)->minutesFromDeparture)->toBe(0);
});

it('compares by arrival instant and minutes', function (): void {
    $arrival = new DateTimeImmutable('2026-10-13T08:20:00+00:00');

    expect(Eta::at($arrival, 50)->equals(Eta::at($arrival, 50)))->toBeTrue()
        ->and(Eta::at($arrival, 50)->equals(Eta::at($arrival, 40)))->toBeFalse();
});

it('rejects negative minutes', function (): void {
    expect(fn () => Eta::at(new DateTimeImmutable, -1))
        ->toThrow(PlanningException::class, 'no pueden ser negativos');
});
