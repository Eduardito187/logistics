<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordServiceDone;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar que el servicio de un plan se ejecutó (fija el último servicio y recalcula el
 * próximo vencimiento). El odómetro llega como km (entero) opcional.
 */
final readonly class RecordServiceDone implements Command
{
    public function __construct(
        public string $planId,
        public ?int $odometerKm = null,
    ) {}
}
