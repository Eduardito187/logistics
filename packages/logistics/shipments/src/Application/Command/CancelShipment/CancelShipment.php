<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\CancelShipment;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de anular un envío (ADR 020). Lo dispara el reactor de cancelación de órdenes comerciales
 * (pre-despacho) o una anulación operativa. El motivo es opcional (p. ej. `order_cancelled`).
 */
final readonly class CancelShipment implements Command
{
    public function __construct(
        public string $shipmentId,
        public ?string $reason = null,
    ) {}
}
