<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\UpdateTruckPosition;

use Logistics\Shared\Application\Command\Command;

/**
 * Actualiza la posición en vivo del camión que lleva un envío.
 *
 * Llega como primitivos desde la telemetría ya normalizada: coordenadas y el instante del reporte en
 * ISO 8601. El handler arma el VO del dominio y valida rangos.
 */
final readonly class UpdateTruckPosition implements Command
{
    public function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
        public string $reportedAt,
    ) {}
}
