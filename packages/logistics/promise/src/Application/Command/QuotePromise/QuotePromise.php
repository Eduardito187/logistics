<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\QuotePromise;

use Logistics\Shared\Application\Command\Command;

/**
 * Cotiza una promesa de entrega para un envío en una franja concreta de una zona. La franja se expresa
 * en primitivos (fecha ISO Y-m-d + etiqueta de franja); el handler valida que siga disponible antes de
 * dejar la promesa cotizada.
 */
final readonly class QuotePromise implements Command
{
    public function __construct(
        public string $shipmentRef,
        public string $zoneId,
        public string $slotDate,
        public string $timeSlot,
    ) {}
}
