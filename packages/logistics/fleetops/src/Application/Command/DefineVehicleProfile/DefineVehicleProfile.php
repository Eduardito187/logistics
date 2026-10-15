<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\DefineVehicleProfile;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir (o re-definir) el perfil técnico de un vehículo. Upsert por `vehicleId`.
 *
 * El combustible llega como su valor primitivo (p. ej. 'diesel') para no acoplar la entrada al enum.
 */
final readonly class DefineVehicleProfile implements Command
{
    public function __construct(
        public string $vehicleId,
        public ?string $brand = null,
        public ?string $model = null,
        public ?int $year = null,
        public ?string $fuelType = null,
        public ?float $tankLiters = null,
        public ?string $vin = null,
        public ?string $color = null,
    ) {}
}
