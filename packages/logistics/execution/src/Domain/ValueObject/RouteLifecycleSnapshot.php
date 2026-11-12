<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Foto inmutable del ciclo de vida de una ruta tal como quedó persistida: cuándo se inició y se cerró, y
 * el odómetro informado en cada momento. Solo agrupa los datos para reconstituir el agregado VehicleRoute
 * (no valida: lo que viene de la persistencia ya pasó por las reglas del agregado al grabarse).
 */
final readonly class RouteLifecycleSnapshot
{
    public function __construct(
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $closedAt = null,
        public ?float $startOdometerKm = null,
        public ?float $closeOdometerKm = null,
    ) {}
}
