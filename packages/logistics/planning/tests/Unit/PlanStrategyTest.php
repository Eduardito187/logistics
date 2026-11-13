<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

it('builds from a valid string, trimming whitespace', function (): void {
    expect(PlanStrategy::fromString('optimization'))->toBe(PlanStrategy::Optimization)
        ->and(PlanStrategy::fromString('  slots  '))->toBe(PlanStrategy::Slots);
});

it('rejects an unknown strategy with a domain error', function (): void {
    expect(fn () => PlanStrategy::fromString('express'))
        ->toThrow(PlanningException::class, 'estrategia de planificación inválida');
});

it('exposes an English label for every case', function (): void {
    expect(PlanStrategy::Optimization->label())->toBe('Optimization (capacity + proximity)')
        ->and(PlanStrategy::Slots->label())->toBe('Delivery slots');
});
