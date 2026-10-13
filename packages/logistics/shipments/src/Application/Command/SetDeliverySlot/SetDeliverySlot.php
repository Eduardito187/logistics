<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\SetDeliverySlot;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de fijar la franja de entrega de un envío: un día más una ventana horaria (HH:MM–HH:MM).
 *
 * El cupo de despacho lo descuenta el contexto de capacidad; este comando solo registra en el envío la
 * franja ya reservada. DTO inmutable de primitivos: el handler los convierte al value object del dominio.
 */
final readonly class SetDeliverySlot implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $deliveryDate,
        public string $windowStart,
        public string $windowEnd,
    ) {}
}
