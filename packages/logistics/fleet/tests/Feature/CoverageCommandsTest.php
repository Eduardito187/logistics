<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\RegisterHelper\RegisterHelper;
use Logistics\Fleet\Application\Command\UpdateDriverProfile\UpdateDriverProfile;
use Logistics\Fleet\Application\Command\UpdateHelperProfile\UpdateHelperProfile;
use Logistics\Fleet\Database\Factories\DriverFactory;
use Logistics\Fleet\Database\Factories\HelperFactory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\HelperModel;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los command handlers del contexto fleet sin cubrir (Patrón C del brief): se despachan por
 * el CommandBus (con su decorador transaccional) y se verifica la persistencia del round-trip, lo que
 * ejercita los handlers, los value objects de datos personales (PersonalReference, BirthDate, PhoneNumber,
 * NationalId, LicenseCategory) y el camino de escritura de los mappers Eloquent.
 */
it('registra un ayudante nuevo y lo persiste', function (): void {
    app(CommandBus::class)->dispatch(new RegisterHelper(
        name: 'Carlos Mamani',
        phone: '70123456',
        documents: [
            ['type' => 'health_card', 'expires_on' => '2027-06-30'],
        ],
        active: true,
    ));

    $helper = HelperModel::query()->where('name', 'Carlos Mamani')->firstOrFail();

    expect($helper->phone)->toBe('70123456')
        ->and($helper->active)->toBeTrue();
});

it('actualiza la ficha personal de un chofer existente', function (): void {
    $driver = DriverFactory::new()->create();
    $driverId = (string) $driver->getKey();

    app(CommandBus::class)->dispatch(new UpdateDriverProfile(
        driverId: $driverId,
        photoPath: 'fleet-driver-photos/portrait.jpg',
        birthDate: '1990-05-12',
        nationalId: '7654321',
        phonePrimary: '70111222',
        phoneSecondary: '70333444',
        address: 'Av. Siempre Viva 742',
        licenseCategory: 'B',
        emergencyContactName: 'Maria Perez',
        emergencyContactPhone: '70999888',
        references: [
            ['name' => 'Jose Quispe', 'relationship' => 'Brother', 'phone' => '70555666', 'note' => 'Lives nearby'],
        ],
        licenseCity: 'La Paz',
    ));

    $row = DriverModel::query()->findOrFail($driverId);

    expect($row->national_id)->toBe('7654321')
        ->and($row->phone_primary)->toBe('70111222')
        ->and($row->address)->toBe('Av. Siempre Viva 742');
});

it('actualiza la ficha personal de un ayudante existente', function (): void {
    $helper = HelperFactory::new()->create();
    $helperId = (string) $helper->getKey();

    app(CommandBus::class)->dispatch(new UpdateHelperProfile(
        helperId: $helperId,
        photoPath: 'fleet-helper-photos/portrait.jpg',
        birthDate: '1995-08-20',
        nationalId: '1234567',
        phoneSecondary: '70222333',
        address: 'Calle Falsa 123',
        emergencyContactName: 'Ana Flores',
        emergencyContactPhone: '70444555',
        references: [
            ['name' => 'Luis Rojas', 'relationship' => 'Friend', 'phone' => '70666777', 'note' => null],
        ],
    ));

    $row = HelperModel::query()->findOrFail($helperId);

    expect($row->national_id)->toBe('1234567')
        ->and($row->address)->toBe('Calle Falsa 123');
});
