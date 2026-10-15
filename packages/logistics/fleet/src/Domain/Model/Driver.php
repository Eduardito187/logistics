<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\DriverProfileUpdated;
use Logistics\Fleet\Domain\Event\DriverRegistered;
use Logistics\Fleet\Domain\ValueObject\BirthDate;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\DriverProfile;
use Logistics\Fleet\Domain\ValueObject\LicenseCategory;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Fleet\Domain\ValueObject\NationalId;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;
use Logistics\Fleet\Domain\ValueObject\PhoneNumber;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Chofer de la flota: nombre, licencia con su vencimiento, vehículos habilitados y estado activo.
 *
 * La lista de vehículos habilitados se guarda como ids en texto (referencia a VehicleId por valor): un
 * chofer puede conducir solo los vehículos para los que está habilitado. La validez de la licencia
 * depende de su vencimiento y de que el chofer esté activo.
 *
 * Además lleva datos personales opcionales (foto, CI, fecha de nacimiento, teléfonos, dirección,
 * categoría de licencia, contacto de emergencia) y referencias personales, que se completan por separado
 * con `updateProfile()`. Son nullable porque el perfil se enriquece de a poco y los choferes ya
 * registrados siguen válidos sin esos datos.
 */
final class Driver extends AggregateRoot
{
    /**
     * @param list<string>            $enabledVehicleIds
     * @param list<PersonalReference> $references
     */
    private function __construct(
        private readonly DriverId $id,
        private readonly string $name,
        private readonly LicenseNumber $license,
        private readonly DocumentExpiry $licenseExpiry,
        private array $enabledVehicleIds,
        private bool $active,
        private ?string $photoPath = null,
        private ?BirthDate $birthDate = null,
        private ?NationalId $nationalId = null,
        private ?PhoneNumber $phonePrimary = null,
        private ?PhoneNumber $phoneSecondary = null,
        private ?string $address = null,
        private ?LicenseCategory $licenseCategory = null,
        private ?string $emergencyContactName = null,
        private ?PhoneNumber $emergencyContactPhone = null,
        private array $references = [],
        private ?string $licenseCity = null,
    ) {}

    /**
     * Registra un chofer nuevo. Arranca activo salvo que se indique lo contrario.
     *
     * Los datos personales son opcionales y se pueden completar luego con `updateProfile()`.
     *
     * @param list<string>            $enabledVehicleIds
     * @param list<PersonalReference> $references
     */
    public static function register(
        DriverId $id,
        string $name,
        LicenseNumber $license,
        DocumentExpiry $licenseExpiry,
        array $enabledVehicleIds = [],
        bool $active = true,
        ?string $photoPath = null,
        ?BirthDate $birthDate = null,
        ?NationalId $nationalId = null,
        ?PhoneNumber $phonePrimary = null,
        ?PhoneNumber $phoneSecondary = null,
        ?string $address = null,
        ?LicenseCategory $licenseCategory = null,
        ?string $emergencyContactName = null,
        ?PhoneNumber $emergencyContactPhone = null,
        array $references = [],
        ?string $licenseCity = null,
    ): self {
        $driver = new self(
            $id,
            $name,
            $license,
            $licenseExpiry,
            array_values(array_unique($enabledVehicleIds)),
            $active,
            $photoPath,
            $birthDate,
            $nationalId,
            $phonePrimary,
            $phoneSecondary,
            $address,
            $licenseCategory,
            $emergencyContactName,
            $emergencyContactPhone,
            array_values($references),
            $licenseCity,
        );
        $driver->recordEvent(new DriverRegistered(
            $id->toString(),
            $name,
            $license->value,
            $licenseExpiry->expiresOn->format('Y-m-d'),
            $active,
        ));

        return $driver;
    }

    /**
     * Reconstituye un chofer desde persistencia, sin grabar eventos.
     *
     * @param list<string>            $enabledVehicleIds
     * @param list<PersonalReference> $references
     */
    public static function reconstitute(
        DriverId $id,
        string $name,
        LicenseNumber $license,
        DocumentExpiry $licenseExpiry,
        array $enabledVehicleIds,
        bool $active,
        ?string $photoPath = null,
        ?BirthDate $birthDate = null,
        ?NationalId $nationalId = null,
        ?PhoneNumber $phonePrimary = null,
        ?PhoneNumber $phoneSecondary = null,
        ?string $address = null,
        ?LicenseCategory $licenseCategory = null,
        ?string $emergencyContactName = null,
        ?PhoneNumber $emergencyContactPhone = null,
        array $references = [],
        ?string $licenseCity = null,
    ): self {
        return new self(
            $id,
            $name,
            $license,
            $licenseExpiry,
            array_values($enabledVehicleIds),
            $active,
            $photoPath,
            $birthDate,
            $nationalId,
            $phonePrimary,
            $phoneSecondary,
            $address,
            $licenseCategory,
            $emergencyContactName,
            $emergencyContactPhone,
            array_values($references),
            $licenseCity,
        );
    }

    /**
     * Habilita al chofer para conducir un vehículo. Idempotente: no duplica.
     */
    public function enableForVehicle(string $vehicleId): void
    {
        if (!in_array($vehicleId, $this->enabledVehicleIds, true)) {
            $this->enabledVehicleIds[] = $vehicleId;
        }
    }

    public function isEnabledFor(string $vehicleId): bool
    {
        return in_array($vehicleId, $this->enabledVehicleIds, true);
    }

    /**
     * ¿Puede conducir en la fecha dada? Requiere estar activo y tener la licencia vigente.
     */
    public function isLicenseValidAt(DateTimeImmutable $ref): bool
    {
        return $this->active && !$this->licenseExpiry->isExpiredAt($ref);
    }

    /**
     * Reemplaza por completo los datos personales del chofer y graba el evento de perfil actualizado.
     */
    public function updateProfile(DriverProfile $profile): void
    {
        $this->photoPath = $profile->photoPath;
        $this->birthDate = $profile->birthDate;
        $this->nationalId = $profile->nationalId;
        $this->phonePrimary = $profile->phonePrimary;
        $this->phoneSecondary = $profile->phoneSecondary;
        $this->address = $profile->address;
        $this->licenseCategory = $profile->licenseCategory;
        $this->licenseCity = $profile->licenseCity;
        $this->emergencyContactName = $profile->emergencyContactName;
        $this->emergencyContactPhone = $profile->emergencyContactPhone;
        $this->references = array_values($profile->references);

        $this->recordEvent(new DriverProfileUpdated($this->id->toString(), $profile->toArray()));
    }

    public function id(): DriverId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function license(): LicenseNumber
    {
        return $this->license;
    }

    public function licenseExpiry(): DocumentExpiry
    {
        return $this->licenseExpiry;
    }

    /**
     * @return list<string>
     */
    public function enabledVehicleIds(): array
    {
        return $this->enabledVehicleIds;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function photoPath(): ?string
    {
        return $this->photoPath;
    }

    public function birthDate(): ?BirthDate
    {
        return $this->birthDate;
    }

    public function nationalId(): ?NationalId
    {
        return $this->nationalId;
    }

    public function phonePrimary(): ?PhoneNumber
    {
        return $this->phonePrimary;
    }

    public function phoneSecondary(): ?PhoneNumber
    {
        return $this->phoneSecondary;
    }

    public function address(): ?string
    {
        return $this->address;
    }

    public function licenseCategory(): ?LicenseCategory
    {
        return $this->licenseCategory;
    }

    public function licenseCity(): ?string
    {
        return $this->licenseCity;
    }

    public function emergencyContactName(): ?string
    {
        return $this->emergencyContactName;
    }

    public function emergencyContactPhone(): ?PhoneNumber
    {
        return $this->emergencyContactPhone;
    }

    /**
     * @return list<PersonalReference>
     */
    public function references(): array
    {
        return $this->references;
    }
}
