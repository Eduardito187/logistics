<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;

/**
 * Cubre los caminos del perfil que no ejercita el test base: vehículo vacío al definir, la actualización
 * de ficha (updateSpecs) con su validación de tanque, la reconstitución y los accesores restantes.
 */
it('rechaza definir un perfil sin vehículo', function (): void {
    expect(fn () => VehicleProfile::define(
        VehicleProfileId::generate(),
        '   ',
        null, null, null, null, null, null, null,
    ))->toThrow(FleetOpsException::class, 'no puede estar vacío');
});

it('reconstituye un perfil con odómetro vigente sin grabar eventos', function (): void {
    $profile = VehicleProfile::reconstitute(
        VehicleProfileId::generate(),
        'vehicle-9',
        'Scania',
        'R450',
        2021,
        FuelType::Diesel,
        300.0,
        'VIN-9',
        'Red',
        Odometer::km(88_000),
    );

    expect($profile->vehicleId())->toBe('vehicle-9')
        ->and($profile->brand())->toBe('Scania')
        ->and($profile->model())->toBe('R450')
        ->and($profile->year())->toBe(2021)
        ->and($profile->vin())->toBe('VIN-9')
        ->and($profile->color())->toBe('Red')
        ->and($profile->currentOdometer()?->km)->toBe(88_000)
        ->and($profile->releaseEvents())->toBe([]);
});

it('actualiza la ficha técnica sin tocar el odómetro ni grabar eventos', function (): void {
    $profile = VehicleProfile::reconstitute(
        VehicleProfileId::generate(),
        'vehicle-9',
        'Scania', 'R450', 2021, FuelType::Diesel, 300.0, 'VIN-9', 'Red',
        Odometer::km(88_000),
    );

    $profile->updateSpecs('Volvo', 'FH16', 2023, FuelType::Gnv, 420.0, 'VIN-NEW', 'Blue');

    expect($profile->brand())->toBe('Volvo')
        ->and($profile->model())->toBe('FH16')
        ->and($profile->year())->toBe(2023)
        ->and($profile->fuelType())->toBe(FuelType::Gnv)
        ->and($profile->tankLiters())->toBe(420.0)
        ->and($profile->vin())->toBe('VIN-NEW')
        ->and($profile->color())->toBe('Blue')
        // El odómetro vigente no cambia con updateSpecs.
        ->and($profile->currentOdometer()?->km)->toBe(88_000)
        ->and($profile->releaseEvents())->toBe([]);
});

it('rechaza un tanque no positivo al actualizar la ficha', function (): void {
    $profile = VehicleProfile::reconstitute(
        VehicleProfileId::generate(),
        'vehicle-9',
        null, null, null, null, null, null, null, null,
    );

    expect(fn () => $profile->updateSpecs(null, null, null, null, -5.0, null, null))
        ->toThrow(FleetOpsException::class, 'tanque debe ser positiva');
});
