<?php

declare(strict_types=1);

use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\RoutePosition;

// Posición 1-based del envío en la ruta: deriva las paradas que faltan antes de la del cliente.

it('construye una posición 1-based', function (): void {
    expect(RoutePosition::of(1)->value)->toBe(1)
        ->and(RoutePosition::of(5)->value)->toBe(5);
});

it('rechaza una posición menor a 1', function (): void {
    expect(fn () => RoutePosition::of(0))
        ->toThrow(TrackingException::class, 'posición');
});

it('deriva las paradas previas a la del cliente', function (): void {
    expect(RoutePosition::of(1)->stopsBefore())->toBe(0)
        ->and(RoutePosition::of(3)->stopsBefore())->toBe(2);
});

it('compara por valor', function (): void {
    expect(RoutePosition::of(3)->equals(RoutePosition::of(3)))->toBeTrue()
        ->and(RoutePosition::of(3)->equals(RoutePosition::of(4)))->toBeFalse();
});
