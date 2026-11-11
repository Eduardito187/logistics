<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\RescheduleShipmentDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de REPROGRAMAR la entrega de un envío: nueva fecha + ventana (y un motivo opcional). Válido
 * tras el pago y antes de entregar. El seguimiento lo proyecta como «reprogramada» y avisa al cliente.
 */
final readonly class RescheduleShipmentDelivery implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $deliveryDate,
        public string $windowStart,
        public string $windowEnd,
        public ?string $reason = null,
    ) {}
}
