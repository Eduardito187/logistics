<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;

/**
 * Pide la posición en vivo de toda la flota (la última lectura conocida de cada vehículo).
 *
 * `since` (opcional): solo los vehículos cuya posición cambió (hora del servidor) después de ese instante,
 * para el polling incremental. `enrich` (por defecto sí): resolver placa y chofer en lote.
 */
final readonly class GetLiveFleetPositions implements Query
{
    public function __construct(
        public ?DateTimeImmutable $since = null,
        public bool $enrich = true,
    ) {}
}
