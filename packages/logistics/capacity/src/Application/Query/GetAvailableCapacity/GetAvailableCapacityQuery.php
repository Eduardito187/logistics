<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetAvailableCapacity;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el cupo (con su disponible) de un scope, fecha, franja y unidad. Devuelve null si no existe.
 */
final readonly class GetAvailableCapacityQuery implements Query
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
    ) {}
}
