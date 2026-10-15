<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\ValueObject\Speed;

// VO Speed: no negativa, tope defensivo, detección de movimiento y exceso. PHP puro.

it('construye una velocidad válida y la velocidad cero', function (): void {
    expect(Speed::kmh(50.0)->kph)->toBe(50.0)
        ->and(Speed::zero()->kph)->toBe(0.0);
});

it('rechaza velocidades negativas', function (): void {
    expect(fn () => Speed::kmh(-1.0))->toThrow(TelemetryException::class);
});

it('recorta lecturas absurdas al tope defensivo de 300 km/h', function (): void {
    expect(Speed::kmh(500.0)->kph)->toBe(300.0);
});

it('detecta movimiento por sobre el umbral', function (): void {
    expect(Speed::kmh(2.0)->isMoving())->toBeFalse()
        ->and(Speed::kmh(3.0)->isMoving())->toBeTrue()
        ->and(Speed::kmh(10.0)->isMoving())->toBeTrue()
        ->and(Speed::kmh(4.0)->isMoving(5.0))->toBeFalse();
});

it('detecta exceso por sobre el límite', function (): void {
    expect(Speed::kmh(100.0)->exceeds(90.0))->toBeTrue()
        ->and(Speed::kmh(90.0)->exceeds(90.0))->toBeFalse()
        ->and(Speed::kmh(80.0)->exceeds(90.0))->toBeFalse();
});
