<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ReleaseCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de liberar capacidad de un cupo (p. ej. al reprogramar o cuando un envío falla).
 * Identifica el bucket por su clave natural (scope/fecha/franja/unidad) y la cantidad a devolver.
 */
final readonly class ReleaseCapacityCommand implements Command
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $amount,
        public ?string $municipalityId = null,
    ) {}
}
