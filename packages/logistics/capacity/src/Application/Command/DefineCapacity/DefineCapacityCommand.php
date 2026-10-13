<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\DefineCapacity;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir un cupo nuevo. DTO inmutable con datos primitivos: el handler los convierte en
 * los value objects del dominio y aplica las validaciones. Debe venir exactamente uno de nodeId/zoneId.
 */
final readonly class DefineCapacityCommand implements Command
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $total,
    ) {}
}
