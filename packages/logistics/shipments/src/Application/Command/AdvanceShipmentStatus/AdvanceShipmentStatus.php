<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\AdvanceShipmentStatus;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de AVANZAR el estado de un envío hacia `$targetStatus` (PLANNED, IN_EXECUTION o DELIVERED)
 * a partir de un hecho de planificación o ejecución. El avance es monótono e idempotente: si el envío ya
 * está en ese estado o más adelante (o cerrado), no cambia nada. `$trigger` es el nombre del hecho que lo
 * originó (solo para trazabilidad en logs).
 */
final readonly class AdvanceShipmentStatus implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $targetStatus,
        public ?string $trigger = null,
    ) {}
}
