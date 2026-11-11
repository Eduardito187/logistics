<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\MarkShipmentDeparted;

use Logistics\Shared\Application\Command\Command;

/**
 * Marca que el camión SALIÓ con el envío (el chofer inició la ruta). Mueve la entrega a «en tránsito» y
 * emite ShipmentDeparted, que el seguimiento proyecta como «en camino». Idempotente en el agregado.
 */
final readonly class MarkShipmentDeparted implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $occurredAt,
    ) {}
}
