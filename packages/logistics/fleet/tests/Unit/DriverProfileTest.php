<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DriverProfile;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalProfile;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;

/**
 * Perfil del chofer: todos los campos opcionales, con forma primitiva para persistencia/eventos.
 */
it('expone un perfil vacío con todos los campos nulos', function (): void {
    expect((new DriverProfile)->toArray())->toBe([
        'photo_path' => null,
        'birth_date' => null,
        'national_id' => null,
        'phone_primary' => null,
        'phone_secondary' => null,
        'address' => null,
        'license_category' => null,
        'license_city' => null,
        'emergency_contact_name' => null,
        'emergency_contact_phone' => null,
        'references' => [],
    ]);
});

it('serializa a forma primitiva incluyendo las referencias', function (): void {
    $profile = new DriverProfile(
        photoPath: 'fleet-driver-photos/x.jpg',
        birthDate: BirthDate::of(new DateTimeImmutable('1988-07-01')),
        nationalId: NationalId::of('CI-55667'),
        phonePrimary: PhoneNumber::of('70011122'),
        phoneSecondary: PhoneNumber::of('70033344'),
        address: 'Calle 1',
        licenseCategory: LicenseCategory::B,
        emergencyContactName: 'Rosa',
        emergencyContactPhone: PhoneNumber::of('70055566'),
        references: [PersonalReference::of('Ana', 'Hermana')],
        licenseCity: 'Santa Cruz',
    );

    expect($profile->toArray())->toBe([
        'photo_path' => 'fleet-driver-photos/x.jpg',
        'birth_date' => '1988-07-01',
        'national_id' => 'CI-55667',
        'phone_primary' => '70011122',
        'phone_secondary' => '70033344',
        'address' => 'Calle 1',
        'license_category' => 'B',
        'license_city' => 'Santa Cruz',
        'emergency_contact_name' => 'Rosa',
        'emergency_contact_phone' => '70055566',
        'references' => [
            ['name' => 'Ana', 'relationship' => 'Hermana', 'phone' => null, 'note' => null],
        ],
    ]);
});

it('deriva el núcleo personal común (sin teléfono primario ni licencia)', function (): void {
    $profile = new DriverProfile(
        phonePrimary: PhoneNumber::of('70011122'),
        phoneSecondary: PhoneNumber::of('70033344'),
        address: 'Calle 1',
        licenseCategory: LicenseCategory::C,
    );

    $personal = $profile->personal();

    expect($personal)->toBeInstanceOf(PersonalProfile::class)
        ->and($personal->phoneSecondary?->value)->toBe('70033344')
        ->and($personal->address)->toBe('Calle 1')
        ->and($personal->toArray())->not->toHaveKey('phone_primary')
        ->and($personal->toArray())->not->toHaveKey('license_category');
});
