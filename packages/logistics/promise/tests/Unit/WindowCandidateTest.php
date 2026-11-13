<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\WindowCandidate;

/*
| VO WindowCandidate (candidata a ventana sobre la que puntúa WindowSelector). Prueban la deducción de
| bordes horarios desde 'HH:MM-HH:MM', las etiquetas sin bordes, la factibilidad por cupo y la rama
| de franja vacía.
*/

it('deduce los bordes horarios desde una etiqueta HH:MM-HH:MM', function (): void {
    $candidate = WindowCandidate::of('09:00-13:00', 4.0);

    expect($candidate->timeSlot)->toBe('09:00-13:00')
        ->and($candidate->startMinute)->toBe(9 * 60)
        ->and($candidate->endMinute)->toBe(13 * 60)
        ->and($candidate->windowStart)->toBe('09:00')
        ->and($candidate->windowEnd)->toBe('13:00')
        ->and($candidate->hasBounds())->toBeTrue();
});

it('una etiqueta sin rango queda sin bordes horarios', function (): void {
    $candidate = WindowCandidate::of('am', 2.0);

    expect($candidate->timeSlot)->toBe('AM')
        ->and($candidate->startMinute)->toBeNull()
        ->and($candidate->endMinute)->toBeNull()
        ->and($candidate->windowStart)->toBeNull()
        ->and($candidate->windowEnd)->toBeNull()
        ->and($candidate->hasBounds())->toBeFalse();
});

it('isFeasible exige al menos una entrega de cupo', function (): void {
    expect(WindowCandidate::of('AM', 1.0)->isFeasible())->toBeTrue()
        ->and(WindowCandidate::of('AM', 0.9)->isFeasible())->toBeFalse()
        ->and(WindowCandidate::of('AM', 0.0)->isFeasible())->toBeFalse();
});

it('rechaza una franja candidata vacía', function (): void {
    expect(fn () => WindowCandidate::of('  ', 1.0))
        ->toThrow(PromiseException::class, 'no puede estar vacía');
});
