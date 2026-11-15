<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CloseTransferWithShortage;

use Logistics\Shared\Application\Command\Command;

/**
 * CIERRE MANUAL DE OFICINA de un traspaso con faltantes: el chofer dejó el traspaso en su tienda destino
 * declarando productos faltantes y la tienda nunca concilió la recepción, así que quedó en curso. La oficina
 * (admin o supervisor) lo da por entregado con un motivo obligatorio.
 *
 * `closedBy` es el usuario que lo cierra (id de la cuenta) y `occurredAt` la hora del cierre (ISO-8601).
 */
final readonly class CloseTransferWithShortage implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $reason,
        public string $closedBy,
        public string $occurredAt,
    ) {}
}
