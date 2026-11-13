<?php

declare(strict_types=1);

use Logistics\Fleet\Application\ReadModel\DriverView;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\DriverProfile;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;

/**
 * Proyección de lectura del chofer: primitivos para listados/API, sin exponer objetos de dominio.
 */
it('proyecta un chofer sin datos personales con los campos nulos', function (): void {
    $driver = Driver::register(
        DriverId::generate(),
        'Juan Pérez',
        LicenseNumber::of('LIC-00123'),
        DocumentExpiry::of('driver_license', new DateTimeImmutable('2027-01-01')),
        ['veh-1'],
        true,
    );

    $view = DriverView::fromDomain($driver);
    $data = $view->toArray();

    expect($data['id'])->toBe($driver->id()->toString())
        ->and($data['name'])->toBe('Juan Pérez')
        ->and($data['license_number'])->toBe('LIC-00123')
        ->and($data['license_expiry'])->toBe('2027-01-01')
        ->and($data['enabled_vehicle_ids'])->toBe(['veh-1'])
        ->and($data['active'])->toBeTrue()
        ->and($data['birth_date'])->toBeNull()
        ->and($data['age'])->toBeNull()
        ->and($data['national_id'])->toBeNull()
        ->and($data['license_category'])->toBeNull()
        ->and($data['references'])->toBe([]);
});

it('proyecta un chofer con todos sus datos personales y referencias', function (): void {
    $driver = Driver::register(
        DriverId::generate(),
        'María López',
        LicenseNumber::of('LIC-77777'),
        DocumentExpiry::of('driver_license', new DateTimeImmutable('2028-06-30')),
        [],
        true,
    );

    $driver->updateProfile(new DriverProfile(
        photoPath: 'fleet-driver-photos/maria.jpg',
        birthDate: BirthDate::of(new DateTimeImmutable('1990-01-15')),
        nationalId: NationalId::of('CI-9988776'),
        phonePrimary: PhoneNumber::of('70011122'),
        phoneSecondary: PhoneNumber::of('70033344'),
        address: 'Av. Siempre Viva 123',
        licenseCategory: LicenseCategory::B,
        emergencyContactName: 'Pedro López',
        emergencyContactPhone: PhoneNumber::of('70055566'),
        references: [PersonalReference::of('Ana', 'Hermana', '70077788', 'Vecina')],
        licenseCity: 'Santa Cruz',
    ));

    $data = DriverView::fromDomain($driver)->toArray();

    expect($data['photo_path'])->toBe('fleet-driver-photos/maria.jpg')
        ->and($data['birth_date'])->toBe('1990-01-15')
        ->and($data['age'])->toBeInt()->toBeGreaterThan(30)
        ->and($data['national_id'])->toBe('CI-9988776')
        ->and($data['phone_primary'])->toBe('70011122')
        ->and($data['phone_secondary'])->toBe('70033344')
        ->and($data['address'])->toBe('Av. Siempre Viva 123')
        ->and($data['license_category'])->toBe('B')
        ->and($data['emergency_contact_name'])->toBe('Pedro López')
        ->and($data['emergency_contact_phone'])->toBe('70055566')
        ->and($data['references'])->toBe([
            ['name' => 'Ana', 'relationship' => 'Hermana', 'phone' => '70077788', 'note' => 'Vecina'],
        ]);
});
