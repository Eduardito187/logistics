<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetShipmentEta;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la ETA dinámica de UN envío: la de su parada en la ruta del vehículo que lo lleva.
 * Devuelve null si el envío no tiene entrega/vehículo asignado.
 */
final readonly class GetShipmentEta implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
