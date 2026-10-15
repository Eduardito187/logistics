<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

it('builds an open-ended and a closed window', function (): void {
    $open = TimeWindow::of(3600.0);
    $closed = TimeWindow::of(3600.0, 7200.0);

    expect($open->earliestSeconds)->toBe(3600.0)
        ->and($open->latestSeconds)->toBeNull()
        ->and($closed->earliestSeconds)->toBe(3600.0)
        ->and($closed->latestSeconds)->toBe(7200.0);
});

it('rejects a negative opening', function (): void {
    expect(fn () => TimeWindow::of(-1.0))->toThrow(PlanningException::class);
});

it('rejects a window that closes before it opens', function (): void {
    expect(fn () => TimeWindow::of(7200.0, 3600.0))->toThrow(PlanningException::class);
});

it('allows a degenerate window that opens and closes at the same instant', function (): void {
    $window = TimeWindow::of(3600.0, 3600.0);

    expect($window->earliestSeconds)->toBe(3600.0)
        ->and($window->latestSeconds)->toBe(3600.0);
});

it('reports the wait before the window opens and no wait once open', function (): void {
    $window = TimeWindow::of(3600.0, 7200.0);

    // Llegar a los 1000 s obliga a esperar 2600 s a la apertura.
    expect($window->waitAt(1000.0))->toBe(2600.0)
        // Llegar justo en la apertura o después no espera.
        ->and($window->waitAt(3600.0))->toBe(0.0)
        ->and($window->waitAt(5000.0))->toBe(0.0);
});

it('reports lateness past the close and none before it', function (): void {
    $window = TimeWindow::of(3600.0, 7200.0);

    expect($window->latenessAt(7000.0))->toBe(0.0)
        ->and($window->latenessAt(7200.0))->toBe(0.0)
        ->and($window->latenessAt(8000.0))->toBe(800.0);
});

it('never reports lateness for an open-ended window', function (): void {
    $window = TimeWindow::of(3600.0);

    expect($window->latenessAt(999999.0))->toBe(0.0);
});

it('moves an early arrival to the opening and leaves a late one untouched', function (): void {
    $window = TimeWindow::of(3600.0, 7200.0);

    // Arribo temprano: se corre a la apertura (el camión espera).
    expect($window->effectiveArrivalFrom(1000.0))->toBe(3600.0)
        // Arribo dentro/después de la apertura: se respeta tal cual.
        ->and($window->effectiveArrivalFrom(5000.0))->toBe(5000.0)
        ->and($window->effectiveArrivalFrom(9000.0))->toBe(9000.0);
});
