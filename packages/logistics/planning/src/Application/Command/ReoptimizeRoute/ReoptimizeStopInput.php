<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReoptimizeRoute;

/**
 * Una parada del plan a re-optimizar, con las coordenadas (y, opcional, la franja horaria) que el motor
 * necesita para re-secuenciar. Las aporta quien conoce el envío; planificación no consulta el contexto de
 * Envíos. Es un DTO de entrada del comando, no un value object de dominio.
 */
final readonly class ReoptimizeStopInput
{
    public function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
        public ?string $windowStart = null,
        public ?string $windowEnd = null,
    ) {}
}
