<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListPreTripChecklists;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las inspecciones pre-salida. Opcionalmente filtra por vehículo o por resultado (aprobadas).
 */
final readonly class ListPreTripChecklists implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?bool $onlyPassed = null,
    ) {}
}
