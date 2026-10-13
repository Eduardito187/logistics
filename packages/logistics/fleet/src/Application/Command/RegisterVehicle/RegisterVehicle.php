<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\RegisterVehicle;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar un vehículo nuevo en la flota.
 *
 * Los documentos llegan como pares simples {type, expires_on (Y-m-d)} para no acoplar la entrada a los
 * VO del dominio; el handler los convierte.
 */
final readonly class RegisterVehicle implements Command
{
    /**
     * @param list<array{type: string, expires_on: string}> $documents
     */
    public function __construct(
        public string $plate,
        public int $capacityKg,
        public float $capacityM3,
        public string $homeBaseNodeId,
        public array $documents = [],
        public string $status = 'available',
    ) {}
}
