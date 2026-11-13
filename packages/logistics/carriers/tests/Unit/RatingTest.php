<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\Rating;

/**
 * Calificación entera 1..5: garantiza el rango e implementa igualdad por valor.
 */
it('acepta las calificaciones dentro del rango 1..5', function (): void {
    expect(Rating::of(1)->value)->toBe(1)
        ->and(Rating::of(3)->value)->toBe(3)
        ->and(Rating::of(5)->value)->toBe(5);
});

it('compara dos calificaciones por su valor', function (): void {
    expect(Rating::of(4)->equals(Rating::of(4)))->toBeTrue()
        ->and(Rating::of(4)->equals(Rating::of(2)))->toBeFalse();
});

it('rechaza una calificación por debajo del mínimo', function (): void {
    expect(fn () => Rating::of(0))->toThrow(CarriersException::class, 'entre 1 y 5');
});

it('rechaza una calificación por encima del máximo', function (): void {
    expect(fn () => Rating::of(6))->toThrow(CarriersException::class, 'entre 1 y 5');
});
