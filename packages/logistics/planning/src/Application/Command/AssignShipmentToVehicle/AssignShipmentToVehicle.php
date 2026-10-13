<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\AssignShipmentToVehicle;

use Logistics\Shared\Application\Command\Command;

/**
 * Agrega un envío al plan (camión) indicado. Ajuste manual sobre un plan existente: el envío entra al
 * final de la secuencia; recomponer orden y ETA queda a cargo de reordenar o regenerar la propuesta.
 */
final readonly class AssignShipmentToVehicle implements Command
{
    public function __construct(
        public string $planId,
        public string $shipmentId,
    ) {}
}
