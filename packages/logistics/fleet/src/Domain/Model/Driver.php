<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\DriverRegistered;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Chofer de la flota: nombre, licencia con su vencimiento, vehículos habilitados y estado activo.
 *
 * La lista de vehículos habilitados se guarda como ids en texto (referencia a VehicleId por valor): un
 * chofer puede conducir solo los vehículos para los que está habilitado. La validez de la licencia
 * depende de su vencimiento y de que el chofer esté activo.
 */
final class Driver extends AggregateRoot
{
    /**
     * @param list<string> $enabledVehicleIds
     */
    private function __construct(
        private readonly DriverId $id,
        private readonly string $name,
        private readonly LicenseNumber $license,
        private readonly DocumentExpiry $licenseExpiry,
        private array $enabledVehicleIds,
        private bool $active,
    ) {}

    /**
     * Registra un chofer nuevo. Arranca activo salvo que se indique lo contrario.
     *
     * @param list<string> $enabledVehicleIds
     */
    public static function register(
        DriverId $id,
        string $name,
        LicenseNumber $license,
        DocumentExpiry $licenseExpiry,
        array $enabledVehicleIds = [],
        bool $active = true,
    ): self {
        $driver = new self($id, $name, $license, $licenseExpiry, array_values(array_unique($enabledVehicleIds)), $active);
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
     * @param list<string> $enabledVehicleIds
     */
    public static function reconstitute(
        DriverId $id,
        string $name,
        LicenseNumber $license,
        DocumentExpiry $licenseExpiry,
        array $enabledVehicleIds,
        bool $active,
    ): self {
        return new self($id, $name, $license, $licenseExpiry, array_values($enabledVehicleIds), $active);
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
}
