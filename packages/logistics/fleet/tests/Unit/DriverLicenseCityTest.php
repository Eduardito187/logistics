<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\DriverProfileUpdated;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\DriverProfile;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;

function fleetDriverWithCity(?string $licenseCity): Driver
{
    return Driver::register(
        DriverId::generate(),
        'Juan Pérez',
        LicenseNumber::of('LIC-00123'),
        DocumentExpiry::of('driver_license', new DateTimeImmutable('2027-01-01')),
        [],
        true,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        null,
        [],
        $licenseCity,
    );
}

it('conserva la ciudad de emisión de la licencia al registrar', function (): void {
    $driver = fleetDriverWithCity('La Paz');

    expect($driver->licenseCity())->toBe('La Paz');
});

it('actualiza la ciudad de emisión de la licencia por el perfil y la incluye en el evento', function (): void {
    $driver = fleetDriverWithCity(null);
    $driver->releaseEvents(); // descarta el evento de registro

    $driver->updateProfile(new DriverProfile(
        licenseCategory: LicenseCategory::B,
        licenseCity: 'Santa Cruz',
    ));

    expect($driver->licenseCity())->toBe('Santa Cruz');

    $events = $driver->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DriverProfileUpdated::class)
        ->and($events[0]->profile['license_city'])->toBe('Santa Cruz');
});

it('expone license_city en la forma primitiva del perfil', function (): void {
    $profile = new DriverProfile(licenseCity: 'Cochabamba');

    expect($profile->toArray()['license_city'])->toBe('Cochabamba');
});
