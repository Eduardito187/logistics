<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\Rating;

// Value object de calificación (1-5): rango validado en construcción y derivación de NPS.

it('acepta una calificación dentro de 1..5', function (): void {
    expect(Rating::of(1)->value)->toBe(1)
        ->and(Rating::of(5)->value)->toBe(5);
});

it('rechaza una calificación por debajo del mínimo', function (): void {
    expect(fn () => Rating::of(0))
        ->toThrow(TrackingException::class, 'calificación');
});

it('rechaza una calificación por encima del máximo', function (): void {
    expect(fn () => Rating::of(6))
        ->toThrow(TrackingException::class, 'calificación');
});

it('marca promotor solo al 5', function (): void {
    expect(Rating::of(5)->isPromoter())->toBeTrue()
        ->and(Rating::of(4)->isPromoter())->toBeFalse();
});

it('marca detractor de 1 a 3', function (): void {
    expect(Rating::of(1)->isDetractor())->toBeTrue()
        ->and(Rating::of(3)->isDetractor())->toBeTrue()
        ->and(Rating::of(4)->isDetractor())->toBeFalse();
});

it('compara por valor', function (): void {
    expect(Rating::of(4)->equals(Rating::of(4)))->toBeTrue()
        ->and(Rating::of(4)->equals(Rating::of(5)))->toBeFalse();
});
