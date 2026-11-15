<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveStopFromPlan;

use Logistics\Shared\Application\Command\Command;

/**
 * Quita del plan la parada de un envío. Solo sobre un plan SIN publicar: una hoja de ruta publicada ya no
 * se edita desde planificación (para cambiarla existe el flujo de ejecución).
 */
final readonly class RemoveStopFromPlan implements Command
{
    public function __construct(
        public string $planId,
        public string $shipmentId,
    ) {}
}
