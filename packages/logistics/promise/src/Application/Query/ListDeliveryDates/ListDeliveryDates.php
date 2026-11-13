<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\ListDeliveryDates;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los DÍAS con al menos una ventana factible para un destino (municipio, coordenada o envío), en el
 * horizonte pedido. Es la consulta que usa el canal cuando muestra SOLO fechas (Módulo 1): el cliente
 * elige un día y el sistema le asigna la ventana con `ReserveDeliveryDate`.
 */
final readonly class ListDeliveryDates implements Query
{
    public function __construct(
        public ?string $municipalityId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $shipmentId = null,
        public ?string $fromDate = null,
        public int $horizonDays = 7,
    ) {}
}
