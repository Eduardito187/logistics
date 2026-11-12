<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\SetVehicleRestrictions;

use Logistics\Shared\Application\Command\Command;

/**
 * Define (o reemplaza) las restricciones de ruteo de un camión: tipos de carga admitidos, zonas permitidas
 * y prohibidas, y límites físicos por producto (altura y peso). Null o lista vacía = sin restricción de ese
 * tipo.
 */
final readonly class SetVehicleRestrictions implements Command
{
    /**
     * @param list<string>|null $allowedLoadTypes
     * @param list<string>|null $allowedZones
     * @param list<string>      $forbiddenZones
     */
    public function __construct(
        public string $vehicleId,
        public ?array $allowedLoadTypes = null,
        public ?array $allowedZones = null,
        public array $forbiddenZones = [],
        public ?float $maxHeightM = null,
        public ?float $maxWeightKg = null,
    ) {}
}
