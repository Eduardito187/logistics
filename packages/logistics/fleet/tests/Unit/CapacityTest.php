<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\Capacity;

it('guarda kilogramos enteros y metros cúbicos', function (): void {
    $capacity = Capacity::of(3500, 18.5);

    expect($capacity->kilograms)->toBe(3500)
        ->and($capacity->cubicMeters)->toBe(18.5)
        ->and($capacity->toArray())->toBe(['kg' => 3500, 'm3' => 18.5]);
});

it('redondea el volumen a tres decimales', function (): void {
    expect(Capacity::of(1000, 1.23456)->cubicMeters)->toBe(1.235);
});

it('ofrece una capacidad cero', function (): void {
    $zero = Capacity::zero();

    expect($zero->kilograms)->toBe(0)
        ->and($zero->cubicMeters)->toBe(0.0);
});

it('rechaza capacidades negativas', function (callable $build, string $reason): void {
    expect($build)->toThrow(FleetException::class, $reason);
})->with([
    'kg negativo' => [fn () => Capacity::of(-1, 1.0), 'kg no puede ser negativa'],
    'm³ negativo' => [fn () => Capacity::of(1, -0.5), 'm³ no puede ser negativa'],
]);
