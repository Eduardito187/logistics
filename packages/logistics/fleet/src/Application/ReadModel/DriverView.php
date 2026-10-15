<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;

/**
 * Proyección de lectura de un chofer para listados y API. Primitivos: no expone objetos de dominio.
 *
 * Incluye los datos personales (aditivo): foto, CI, fecha de nacimiento con la edad ya calculada,
 * teléfonos, dirección, categoría de licencia, contacto de emergencia y referencias.
 */
final readonly class DriverView
{
    /**
     * @param list<string>                                                                                $enabledVehicleIds
     * @param list<array{name: string, relationship: string|null, phone: string|null, note: string|null}> $references
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $licenseNumber,
        public string $licenseExpiry,
        public array $enabledVehicleIds,
        public bool $active,
        public ?string $photoPath,
        public ?string $birthDate,
        public ?int $age,
        public ?string $nationalId,
        public ?string $phonePrimary,
        public ?string $phoneSecondary,
        public ?string $address,
        public ?string $licenseCategory,
        public ?string $emergencyContactName,
        public ?string $emergencyContactPhone,
        public array $references,
    ) {}

    public static function fromDomain(Driver $driver): self
    {
        $birthDate = $driver->birthDate();

        return new self(
            $driver->id()->toString(),
            $driver->name(),
            $driver->license()->value,
            $driver->licenseExpiry()->expiresOn->format('Y-m-d'),
            $driver->enabledVehicleIds(),
            $driver->isActive(),
            $driver->photoPath(),
            $birthDate?->toString(),
            $birthDate?->ageAt(new DateTimeImmutable),
            $driver->nationalId()?->value,
            $driver->phonePrimary()?->value,
            $driver->phoneSecondary()?->value,
            $driver->address(),
            $driver->licenseCategory()?->value,
            $driver->emergencyContactName(),
            $driver->emergencyContactPhone()?->value,
            array_values(array_map(
                static fn (PersonalReference $reference): array => $reference->toArray(),
                $driver->references(),
            )),
        );
    }

    /**
     * @return array{
     *     id: string, name: string, license_number: string, license_expiry: string,
     *     enabled_vehicle_ids: list<string>, active: bool, photo_path: string|null, birth_date: string|null,
     *     age: int|null, national_id: string|null, phone_primary: string|null, phone_secondary: string|null,
     *     address: string|null, license_category: string|null, emergency_contact_name: string|null,
     *     emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'license_number' => $this->licenseNumber,
            'license_expiry' => $this->licenseExpiry,
            'enabled_vehicle_ids' => $this->enabledVehicleIds,
            'active' => $this->active,
            'photo_path' => $this->photoPath,
            'birth_date' => $this->birthDate,
            'age' => $this->age,
            'national_id' => $this->nationalId,
            'phone_primary' => $this->phonePrimary,
            'phone_secondary' => $this->phoneSecondary,
            'address' => $this->address,
            'license_category' => $this->licenseCategory,
            'emergency_contact_name' => $this->emergencyContactName,
            'emergency_contact_phone' => $this->emergencyContactPhone,
            'references' => $this->references,
        ];
    }
}
