<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\ServiceWindow;

/*
| VO ServiceWindow (ventana de atención de la zona en minutos del día). Prueban la construcción desde
| minutos y desde 'HH:MM', las ramas de validación y el solape estricto de rangos.
*/

it('construye la ventana desde minutos del día', function (): void {
    $window = ServiceWindow::of(9 * 60, 13 * 60);

    expect($window->startMinute)->toBe(540)
        ->and($window->endMinute)->toBe(780);
});

it('construye la ventana desde horas HH:MM', function (): void {
    $window = ServiceWindow::fromClock('08:30', '18:15');

    expect($window->startMinute)->toBe(8 * 60 + 30)
        ->and($window->endMinute)->toBe(18 * 60 + 15);
});

it('rechaza una ventana fuera del día', function (): void {
    expect(fn () => ServiceWindow::of(-1, 600))->toThrow(PromiseException::class, 'fuera del día');
    expect(fn () => ServiceWindow::of(600, 1441))->toThrow(PromiseException::class, 'fuera del día');
});

it('rechaza una ventana donde el inicio no es anterior al fin', function (): void {
    expect(fn () => ServiceWindow::of(780, 540))->toThrow(PromiseException::class, 'inválida');
    expect(fn () => ServiceWindow::of(600, 600))->toThrow(PromiseException::class, 'inválida');
});

it('rechaza una hora mal formada', function (string $clock): void {
    expect(fn () => ServiceWindow::fromClock($clock, '18:00'))
        ->toThrow(PromiseException::class, 'hora inválida');
})->with([
    'sin minutos' => ['09'],
    'hora fuera de rango' => ['24:00'],
    'texto' => ['nope'],
]);

it('detecta solape estricto con un rango', function (): void {
    $window = ServiceWindow::of(9 * 60, 13 * 60);

    expect($window->overlaps(12 * 60, 14 * 60))->toBeTrue()
        ->and($window->overlaps(8 * 60, 10 * 60))->toBeTrue()
        ->and($window->overlaps(13 * 60, 18 * 60))->toBeFalse()
        ->and($window->overlaps(6 * 60, 9 * 60))->toBeFalse();
});
