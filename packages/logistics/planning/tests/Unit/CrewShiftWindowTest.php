<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\CrewShiftWindow;

it('builds a valid shift window', function (): void {
    $window = CrewShiftWindow::of(480, 1080, 540);

    expect($window->earliestStartMinute)->toBe(480)
        ->and($window->latestEndMinute)->toBe(1080)
        ->and($window->maxDailyMinutes)->toBe(540);
});

it('rejects a negative start minute', function (): void {
    expect(fn () => CrewShiftWindow::of(-1, 1080, 540))
        ->toThrow(PlanningException::class, 'inicio de turno inválido');
});

it('rejects an end that is not after the start', function (): void {
    expect(fn () => CrewShiftWindow::of(480, 480, 540))
        ->toThrow(PlanningException::class, 'debe terminar después de empezar');
});

it('rejects a non-positive max daily minutes', function (): void {
    expect(fn () => CrewShiftWindow::of(480, 1080, 0))
        ->toThrow(PlanningException::class, 'jornada máxima inválida');
});

it('keeps a departure that is already within the window', function (): void {
    $window = CrewShiftWindow::of(480, 1080, 540); // 08:00 - 18:00
    $depart = new DateTimeImmutable('2026-10-13T09:00:00+00:00');

    expect($window->departureFor($depart)->format('H:i'))->toBe('09:00');
});

it('pushes an early departure up to the shift start', function (): void {
    $window = CrewShiftWindow::of(480, 1080, 540); // arranca 08:00
    $depart = new DateTimeImmutable('2026-10-13T06:30:00+00:00');

    expect($window->departureFor($depart)->format('H:i'))->toBe('08:00');
});

it('limits the route to the smaller of the remaining window and the max daily minutes', function (): void {
    $window = CrewShiftWindow::of(480, 1080, 540); // ventana 600 min, jornada 540

    // Sale 08:00: quedan 600 min hasta el fin, pero la jornada máxima (540) manda.
    expect($window->routeLimitMinutes(new DateTimeImmutable('2026-10-13T08:00:00+00:00')))->toBe(540)
        // Sale 16:00: quedan 120 min hasta el fin de la ventana (18:00) y manda eso.
        ->and($window->routeLimitMinutes(new DateTimeImmutable('2026-10-13T16:00:00+00:00')))->toBe(120)
        // Sale 19:00: la ventana ya cerró, no quedan minutos.
        ->and($window->routeLimitMinutes(new DateTimeImmutable('2026-10-13T19:00:00+00:00')))->toBe(0);
});
