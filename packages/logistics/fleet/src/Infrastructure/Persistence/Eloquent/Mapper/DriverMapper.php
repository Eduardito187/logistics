<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\LicenseNumber;
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
        );
    }

    /**
     * @return array{id: string, name: string, license_number: string, license_expiry: string, enabled_vehicle_ids: list<string>, active: bool}
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
        ];
    }
}
