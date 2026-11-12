<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetChecklistTemplate;

use Logistics\Shared\Application\Query\Query;

/**
 * Plantilla activa del checklist pre-salida para un tipo de vehículo (null = la por defecto).
 */
final readonly class GetChecklistTemplate implements Query
{
    public function __construct(
        public ?string $vehicleType = null,
    ) {}
}
