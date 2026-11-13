<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;

/*
| VO SlotCapacity (cupo libre de una franja). Prueban la normalización de la etiqueta, la rama de
| franja vacía y el umbral de `hasCapacity` (cupo estrictamente positivo).
*/

it('normaliza la etiqueta de franja a mayúsculas', function (): void {
    $capacity = SlotCapacity::of(' am ', 3.0);

    expect($capacity->timeSlot)->toBe('AM')
        ->and($capacity->available)->toBe(3.0);
});

it('rechaza una franja vacía', function (): void {
    expect(fn () => SlotCapacity::of('   ', 1.0))
        ->toThrow(PromiseException::class, 'no puede estar vacía');
});

it('hasCapacity es verdadero solo con cupo estrictamente positivo', function (): void {
    expect(SlotCapacity::of('AM', 0.5)->hasCapacity())->toBeTrue()
        ->and(SlotCapacity::of('AM', 0.0)->hasCapacity())->toBeFalse()
        ->and(SlotCapacity::of('AM', -1.0)->hasCapacity())->toBeFalse();
});
