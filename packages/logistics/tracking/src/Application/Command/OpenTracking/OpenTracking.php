<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\OpenTracking;

use Logistics\Shared\Application\Command\Command;

/**
 * Abre el seguimiento de un envío. Normalmente lo dispara la recepción del envío (shipments); el
 * token público lo genera el handler, no el llamador.
 *
 * Idempotente por shipmentId: reabrir el seguimiento de un envío ya seguido no crea otro.
 */
final readonly class OpenTracking implements Command
{
    public function __construct(
        public string $shipmentId,
        public int $remainingStops = 0,
    ) {}
}
