<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetEffectiveCapacity;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la capacidad EFECTIVA de una clave (scope/fecha/franja/unidad): el disponible real tomando el
 * cupo explícito si existe, o el total por defecto de las reglas configurables si no. Nunca devuelve
 * null: si no hay ni cupo ni regla, responde con capacidad cero (source 'none').
 */
final readonly class GetEffectiveCapacityQuery implements Query
{
    public function __construct(
        public ?string $nodeId,
        public ?string $zoneId,
        public string $date,
        public string $timeSlot,
        public string $unit,
    ) {}
}
