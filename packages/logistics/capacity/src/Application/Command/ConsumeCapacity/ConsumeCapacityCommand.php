<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ConsumeCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de consumir capacidad de un cupo (p. ej. al planificar un envío). Identifica el bucket por
 * su clave natural (scope/fecha/franja/unidad) y la cantidad a imputar.
 */
final readonly class ConsumeCapacityCommand implements Command
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $amount,
    ) {}
}
