<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\GetShipmentSummary;

use Logistics\Shared\Application\Query\Query;

/**
 * Resumen agregado de envíos para el tablero (pedido #16 de la app).
 *
 * `$deliveryDateFrom`/`$deliveryDateTo` (Y-m-d, inclusive) acotan los envíos por fecha prometida; null =
 * todos. `$periodFrom`/`$periodTo` (Y-m-d) acotan planes publicados y alertas abiertas (por defecto, hoy
 * en America/La_Paz: lo resuelve la puerta HTTP). El nodo destino lo FUERZA la puerta para el rol Tienda.
 */
final readonly class GetShipmentSummary implements Query
{
    public function __construct(
        public string $periodFrom,
        public string $periodTo,
        public ?string $deliveryDateFrom = null,
        public ?string $deliveryDateTo = null,
        public ?string $destinationNodeId = null,
        public ?string $deliveryMode = null,
    ) {}
}
