<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterDriver;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un chofer nuevo en la flota.
 */
final readonly class RegisterDriver implements Command
{
    /**
     * @param list<string> $enabledVehicleIds
     */
    public function __construct(
        public string $name,
        public string $licenseNumber,
        public string $licenseExpiry,
        public array $enabledVehicleIds = [],
        public bool $active = true,
        public string $licenseDocumentType = 'driver_license',
    ) {}
}
