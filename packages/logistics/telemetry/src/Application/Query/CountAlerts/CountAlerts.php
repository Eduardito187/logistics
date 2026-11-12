<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\CountAlerts;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;

/**
 * Cuenta alertas con los mismos filtros que ListAlerts (p. ej. las sin reconocer de un rango, para el
 * `meta.unacknowledged_total` del listado de la app). Devuelve un int.
 */
final readonly class CountAlerts implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $type = null,
        public ?bool $acknowledged = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
    ) {}
}
