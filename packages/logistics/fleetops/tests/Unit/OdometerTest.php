<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\Odometer;

it('guarda los kilómetros', function (): void {
    expect(Odometer::km(120_000)->km)->toBe(120_000);
});

it('rechaza kilómetros negativos', function (): void {
    expect(fn () => Odometer::km(-1))->toThrow(FleetOpsException::class, 'no puede ser negativo');
});

it('calcula los kilómetros recorridos desde una lectura anterior', function (): void {
    $earlier = Odometer::km(100_000);
    $later = Odometer::km(100_350);

    expect($later->kmSince($earlier))->toBe(350)
        ->and($later->kmSince($later))->toBe(0);
});

it('rechaza que el odómetro retroceda', function (): void {
    $earlier = Odometer::km(100_500);
    $later = Odometer::km(100_000);

    expect(fn () => $later->kmSince($earlier))->toThrow(FleetOpsException::class, 'no puede retroceder');
});
