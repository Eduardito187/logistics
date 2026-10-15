<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\CalculateDeliveryPromise;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta el cálculo integrado de la promesa de entrega: para una zona (y opcionalmente una fecha de
 * inicio), devuelve las fechas y franjas que se pueden prometer combinando cobertura, capacidad y
 * corredor. Es la consulta que el canal usa para ofrecerle al cliente las opciones reales de entrega.
 */
final readonly class CalculateDeliveryPromise implements Query
{
    /**
     * @param string      $zoneId      zona de cobertura (id primitivo)
     * @param string|null $fromDate    primer día del horizonte (Y-m-d); null = desde hoy
     * @param int         $horizonDays cuántos días hacia adelante se exploran (incluye el día de inicio)
     */
    public function __construct(
        public string $zoneId,
        public ?string $fromDate = null,
        public int $horizonDays = 14,
    ) {}
}
