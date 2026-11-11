<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\BindShipmentToTracking;

use Logistics\Shared\Application\Command\Command;

/**
 * Liga un envío recién creado al seguimiento de su pedido. Lo dispara `shipments.shipment_received` (vía
 * el reactor del outbox), que trae el `shipmentId` interno y el `orderReference` (derivado del external_id).
 *
 * Reconciliación multishipping: un pedido puede generar varios envíos hermanos. El primer envío se liga al
 * seguimiento abierto al crear la orden; los hermanos adicionales abren su propio seguimiento (alineado al
 * estado actual del pedido). Idempotente por `shipmentId`.
 */
final readonly class BindShipmentToTracking implements Command
{
    public function __construct(
        public string $orderReference,
        public string $shipmentId,
        public int $remainingStops = 0,
    ) {}
}
