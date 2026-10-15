<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;

/**
 * Traduce entre el agregado Driver (dominio) y su fila Eloquent.
 */
final class DriverMapper
{
    /** Tipo de documento con el que se guarda el vencimiento de la licencia. */
    private const LICENSE_DOCUMENT_TYPE = 'driver_license';

    public function toDomain(DriverModel $model): Driver
    {
        return Driver::reconstitute(
            DriverId::fromString($model->id),
            $model->name,
            LicenseNumber::of($model->license_number),
            DocumentExpiry::of(self::LICENSE_DOCUMENT_TYPE, new DateTimeImmutable($model->license_expiry)),
            array_values($model->enabled_vehicle_ids),
            $model->active,
            $model->photo_path,
            $model->birth_date !== null ? BirthDate::of(new DateTimeImmutable($model->birth_date)) : null,
            $model->national_id !== null ? NationalId::of($model->national_id) : null,
            $model->phone_primary !== null ? PhoneNumber::of($model->phone_primary) : null,
            $model->phone_secondary !== null ? PhoneNumber::of($model->phone_secondary) : null,
            $model->address,
            $model->license_category !== null ? LicenseCategory::from($model->license_category) : null,
            $model->emergency_contact_name,
            $model->emergency_contact_phone !== null ? PhoneNumber::of($model->emergency_contact_phone) : null,
            self::referencesToDomain($model->references),
            $model->license_city,
        );
    }

    /**
     * @return array{
     *     id: string, name: string, license_number: string, license_expiry: string,
     *     enabled_vehicle_ids: list<string>, active: bool, photo_path: string|null, birth_date: string|null,
     *     national_id: string|null, phone_primary: string|null, phone_secondary: string|null,
     *     address: string|null, license_category: string|null, license_city: string|null,
     *     emergency_contact_name: string|null, emergency_contact_phone: string|null,
     *     references: list<array{name: string, relationship: string|null, phone: string|null, note: string|null}>
     * }
     */
    public function toRow(Driver $driver): array
    {
        return [
            'id' => $driver->id()->toString(),
            'name' => $driver->name(),
            'license_number' => $driver->license()->value,
            'license_expiry' => $driver->licenseExpiry()->expiresOn->format('Y-m-d'),
            'enabled_vehicle_ids' => $driver->enabledVehicleIds(),
            'active' => $driver->isActive(),
            'photo_path' => $driver->photoPath(),
            'birth_date' => $driver->birthDate()?->toString(),
            'national_id' => $driver->nationalId()?->value,
            'phone_primary' => $driver->phonePrimary()?->value,
            'phone_secondary' => $driver->phoneSecondary()?->value,
            'address' => $driver->address(),
            'license_category' => $driver->licenseCategory()?->value,
            'license_city' => $driver->licenseCity(),
            'emergency_contact_name' => $driver->emergencyContactName(),
            'emergency_contact_phone' => $driver->emergencyContactPhone()?->value,
            'references' => array_values(array_map(
                static fn (PersonalReference $reference): array => $reference->toArray(),
                $driver->references(),
            )),
        ];
    }

    /**
     * Convierte las filas jsonb de referencias en VOs del dominio.
     *
     * @param  list<array{name: string, relationship?: string|null, phone?: string|null, note?: string|null}> $references
     * @return list<PersonalReference>
     */
    private static function referencesToDomain(array $references): array
    {
        return array_values(array_map(
            static fn (array $reference): PersonalReference => PersonalReference::of(
                $reference['name'],
                $reference['relationship'] ?? null,
                $reference['phone'] ?? null,
                $reference['note'] ?? null,
            ),
            $references,
        ));
    }
}
