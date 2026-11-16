<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\UpdateTruckPosition;

use Logistics\Shared\Application\Command\Command;

/**
 * Actualiza la posición en vivo del camión que lleva un envío.
 *
 * Llega como primitivos desde la telemetría ya normalizada: coordenadas y el instante del reporte en
 * ISO 8601, más la cinemática GPS OPCIONAL (velocidad en m/s, rumbo en grados, precisión en metros). El
 * handler arma el VO del dominio, valida rangos y, cuando falta velocidad o rumbo, los deriva de la
 * posición anterior.
 */
final readonly class UpdateTruckPosition implements Command
{
    public function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
        public string $reportedAt,
        public ?float $speedMps = null,
        public ?float $headingDeg = null,
        public ?float $accuracyM = null,
    ) {}
}
