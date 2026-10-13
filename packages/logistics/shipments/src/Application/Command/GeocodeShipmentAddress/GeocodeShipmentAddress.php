<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\GeocodeShipmentAddress;

use Logistics\Shared\Application\Command\Command;

/**
 * Pide recalcular la confianza de la dirección de un envío (p. ej. tras geocodificar con un proveedor).
 */
final readonly class GeocodeShipmentAddress implements Command
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
