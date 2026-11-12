<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RemoveShipmentFromCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de la oficina de quitar un envío de la carga de un viaje programado.
 */
final readonly class RemoveShipmentFromCarrierTrip implements Command
{
    public function __construct(
        public string $tripId,
        public string $shipmentId,
    ) {}
}
