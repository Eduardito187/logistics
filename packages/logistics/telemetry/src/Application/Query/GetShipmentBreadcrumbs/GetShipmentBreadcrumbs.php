<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetShipmentBreadcrumbs;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide las migas de pan de un envío: la traza (reducida) del vehículo mientras el envío estuvo a bordo, desde
 * su carga hasta su entrega/fallo (o hasta ahora, si sigue en camino). Null si el envío no tiene entrega.
 */
final readonly class GetShipmentBreadcrumbs implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
