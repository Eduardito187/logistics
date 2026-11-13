<?php

declare(strict_types=1);

use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Fleet\Domain\ValueObject\HelperProfile;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;

/**
 * Proyección de lectura del ayudante: primitivos y documentos adjuntos desde `fleet_documents`.
 */
it('proyecta un ayudante básico sin documentos', function (): void {
    $helper = Helper::register(HelperId::generate(), 'Carlos Ruiz', '70012345');

    $data = HelperView::fromDomain($helper)->toArray();

    expect($data['id'])->toBe($helper->id()->toString())
        ->and($data['name'])->toBe('Carlos Ruiz')
        ->and($data['phone'])->toBe('70012345')
        ->and($data['documents'])->toBe([])
        ->and($data['active'])->toBeTrue()
        ->and($data['birth_date'])->toBeNull()
        ->and($data['references'])->toBe([]);
});

it('proyecta un ayudante con perfil, documentos adjuntos y referencias', function (): void {
    $helper = Helper::register(HelperId::generate(), 'Lucía Vega', null);

    $helper->updateProfile(new HelperProfile(
        photoPath: 'fleet-helper-photos/lucia.jpg',
        birthDate: BirthDate::of(new DateTimeImmutable('1995-03-20')),
        nationalId: NationalId::of('CI-1122334'),
        phoneSecondary: PhoneNumber::of('70099887'),
        address: 'Calle Falsa 456',
        emergencyContactName: 'Rosa Vega',
        emergencyContactPhone: PhoneNumber::of('70044556'),
        references: [PersonalReference::of('Mario')],
    ));

    $documents = [['type' => 'health_card', 'expires_on' => '2027-05-01']];
    $data = HelperView::fromDomain($helper, $documents)->toArray();

    expect($data['phone'])->toBeNull()
        ->and($data['documents'])->toBe($documents)
        ->and($data['photo_path'])->toBe('fleet-helper-photos/lucia.jpg')
        ->and($data['birth_date'])->toBe('1995-03-20')
        ->and($data['age'])->toBeInt()->toBeGreaterThan(25)
        ->and($data['national_id'])->toBe('CI-1122334')
        ->and($data['phone_secondary'])->toBe('70099887')
        ->and($data['address'])->toBe('Calle Falsa 456')
        ->and($data['emergency_contact_name'])->toBe('Rosa Vega')
        ->and($data['emergency_contact_phone'])->toBe('70044556')
        ->and($data['references'])->toBe([
            ['name' => 'Mario', 'relationship' => null, 'phone' => null, 'note' => null],
        ]);
});
