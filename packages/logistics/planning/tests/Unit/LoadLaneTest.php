<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\LoadLane;

it('normalizes the label to uppercase and trims it', function (): void {
    expect(LoadLane::of('  a  ')->label)->toBe('A')
        ->and((string) LoadLane::of('bc'))->toBe('BC');
});

it('rejects an empty label', function (): void {
    expect(fn () => LoadLane::of('   '))
        ->toThrow(PlanningException::class, 'carril de carga no puede estar vacío');
});

it('rejects a label longer than the maximum length', function (): void {
    expect(fn () => LoadLane::of('ABCDEFGHI'))
        ->toThrow(PlanningException::class, 'excede');
});

it('compares by label', function (): void {
    expect(LoadLane::of('A')->equals(LoadLane::of('a')))->toBeTrue()
        ->and(LoadLane::of('A')->equals(LoadLane::of('B')))->toBeFalse();
});
