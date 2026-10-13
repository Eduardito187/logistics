<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListAvailableSlots;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las franjas con cupo disponible en una zona para una fecha. Es la consulta que el canal usa
 * para mostrarle opciones al cliente antes de prometer.
 */
final readonly class ListAvailableSlots implements Query
{
    public function __construct(
        public string $zoneId,
        public string $date,
    ) {}
}
