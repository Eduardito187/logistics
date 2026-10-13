<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\Driver;

/**
 * Proyección de lectura de un chofer para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class DriverView
{
    /**
     * @param list<string> $enabledVehicleIds
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $licenseNumber,
        public string $licenseExpiry,
        public array $enabledVehicleIds,
        public bool $active,
    ) {}

    public static function fromDomain(Driver $driver): self
    {
        return new self(
            $driver->id()->toString(),
            $driver->name(),
            $driver->license()->value,
            $driver->licenseExpiry()->expiresOn->format('Y-m-d'),
            $driver->enabledVehicleIds(),
            $driver->isActive(),
        );
    }

    /**
     * @return array{id: string, name: string, license_number: string, license_expiry: string, enabled_vehicle_ids: list<string>, active: bool}
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
        ];
    }
}
