<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Agrupa los datos personales editables de un chofer (foto, CI, fecha de nacimiento, teléfonos,
 * dirección, categoría y ciudad de emisión de la licencia, contacto de emergencia) y sus referencias
 * personales.
 *
 * Es el argumento de `Driver::updateProfile()`: todos los campos son opcionales (nullable) porque el
 * perfil se completa de a poco. Las referencias se reemplazan por completo en cada actualización.
 */
final readonly class DriverProfile
{
    /**
     * @param list<PersonalReference> $references
     */
    public function __construct(
        public ?string $photoPath = null,
        public ?BirthDate $birthDate = null,
        public ?NationalId $nationalId = null,
        public ?PhoneNumber $phonePrimary = null,
        public ?PhoneNumber $phoneSecondary = null,
        public ?string $address = null,
        public ?LicenseCategory $licenseCategory = null,
        public ?string $emergencyContactName = null,
        public ?PhoneNumber $emergencyContactPhone = null,
        public array $references = [],
        public ?string $licenseCity = null,
    ) {}

    /**
     * Forma primitiva para persistencia y eventos (nunca se serializa el objeto de dominio).
     *
     * @return array{
     *     photo_path: string|null,
     *     birth_date: string|null,
     *     national_id: string|null,
     *     phone_primary: string|null,
     *     phone_secondary: string|null,
     *     address: string|null,
     *     license_category: string|null,
     *     license_city: string|null,
     *     emergency_contact_name: string|null,
     *     emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'photo_path' => $this->photoPath,
            'birth_date' => $this->birthDate?->toString(),
            'national_id' => $this->nationalId?->value,
            'phone_primary' => $this->phonePrimary?->value,
            'phone_secondary' => $this->phoneSecondary?->value,
            'address' => $this->address,
            'license_category' => $this->licenseCategory?->value,
            'license_city' => $this->licenseCity,
            'emergency_contact_name' => $this->emergencyContactName,
            'emergency_contact_phone' => $this->emergencyContactPhone?->value,
            'references' => array_values(array_map(
                static fn (PersonalReference $reference): array => $reference->toArray(),
                $this->references,
            )),
        ];
    }
}
