<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\DriverRegistered;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;

function registrarChofer(string $expiry = '2027-01-01', bool $active = true): Driver
{
    return Driver::register(
        DriverId::generate(),
        'Juan Pérez',
        LicenseNumber::of('LIC-00123'),
        DocumentExpiry::of('driver_license', new DateTimeImmutable($expiry)),
        [],
        $active,
    );
}

it('registra un chofer activo y graba el evento', function (): void {
    $driver = registrarChofer();

    expect($driver->isActive())->toBeTrue()
        ->and($driver->name())->toBe('Juan Pérez')
        ->and($driver->license()->value)->toBe('LIC-00123');

    $events = $driver->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DriverRegistered::class)
        ->and($events[0]->licenseExpiry)->toBe('2027-01-01');
});

it('considera la licencia válida si no venció y el chofer está activo', function (): void {
    $driver = registrarChofer('2026-10-20');

    expect($driver->isLicenseValidAt(new DateTimeImmutable('2026-10-13')))->toBeTrue();
});

it('considera la licencia inválida si venció', function (): void {
    $driver = registrarChofer('2026-10-01');

    expect($driver->isLicenseValidAt(new DateTimeImmutable('2026-10-13')))->toBeFalse();
});

it('considera la licencia inválida si el chofer está inactivo aunque la fecha sea vigente', function (): void {
    $driver = registrarChofer('2027-01-01', active: false);

    expect($driver->isLicenseValidAt(new DateTimeImmutable('2026-10-13')))->toBeFalse();
});

it('habilita vehículos sin duplicar', function (): void {
    $driver = registrarChofer();
    $driver->enableForVehicle('veh-1');
    $driver->enableForVehicle('veh-2');
    $driver->enableForVehicle('veh-1');

    expect($driver->enabledVehicleIds())->toBe(['veh-1', 'veh-2'])
        ->and($driver->isEnabledFor('veh-2'))->toBeTrue()
        ->and($driver->isEnabledFor('veh-9'))->toBeFalse();
});
