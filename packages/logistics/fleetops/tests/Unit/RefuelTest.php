<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\RefuelRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;

function registrarCarga(float $liters = 50.0, int $pricePerLiterCents = 372): Refuel
{
    return Refuel::record(
        RefuelId::generate(),
        'vehicle-1',
        'driver-1',
        FuelType::Diesel,
        $liters,
        Money::of($pricePerLiterCents),
        Odometer::km(120_000),
        'YPFB Central',
        true,
        new DateTimeImmutable('2026-10-10T08:00:00+00:00'),
        null,
    );
}

it('calcula el costo total como precio por litro × litros y graba el evento', function (): void {
    $refuel = registrarCarga(50.0, 372);

    // 372 × 50 = 18600.
    expect($refuel->totalCost()->cents)->toBe(18_600)
        ->and($refuel->liters())->toBe(50.0)
        ->and($refuel->isFullTank())->toBeTrue()
        ->and($refuel->odometer()?->km)->toBe(120_000);

    $events = $refuel->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(RefuelRecorded::class);
});

it('rechaza litros menores o iguales a cero', function (float $liters): void {
    expect(fn () => registrarCarga($liters))->toThrow(FleetOpsException::class, 'mayores a cero');
})->with([0.0, -5.0]);
