<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\VehicleOdometerUpdated;
use Logistics\FleetOps\Domain\Event\VehicleProfileDefined;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;

function definirPerfil(): VehicleProfile
{
    return VehicleProfile::define(
        VehicleProfileId::generate(),
        'vehicle-1',
        'Volvo',
        'FH16',
        2022,
        FuelType::Diesel,
        400.0,
        'VIN123456789',
        'White',
    );
}

it('define un perfil técnico y graba el evento, sin odómetro vigente', function (): void {
    $profile = definirPerfil();

    expect($profile->brand())->toBe('Volvo')
        ->and($profile->fuelType())->toBe(FuelType::Diesel)
        ->and($profile->tankLiters())->toBe(400.0)
        ->and($profile->currentOdometer())->toBeNull();

    $events = $profile->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleProfileDefined::class);
});

it('rechaza un tanque no positivo', function (): void {
    expect(fn () => VehicleProfile::define(
        VehicleProfileId::generate(),
        'vehicle-1',
        null, null, null, null, 0.0, null, null,
    ))->toThrow(FleetOpsException::class, 'tanque debe ser positiva');
});

it('el odómetro vigente solo avanza', function (): void {
    $profile = definirPerfil();
    $profile->releaseEvents();

    $profile->updateOdometer(Odometer::km(120_000));
    expect($profile->currentOdometer()?->km)->toBe(120_000);

    $events = $profile->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleOdometerUpdated::class);

    // Lectura igual: no cambia ni graba evento.
    $profile->updateOdometer(Odometer::km(120_000));
    expect($profile->releaseEvents())->toBe([]);
});

it('rechaza que el odómetro vigente retroceda', function (): void {
    $profile = definirPerfil();
    $profile->updateOdometer(Odometer::km(120_000));

    expect(fn () => $profile->updateOdometer(Odometer::km(119_000)))
        ->toThrow(FleetOpsException::class, 'no puede retroceder');
});
