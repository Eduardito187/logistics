<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\AggregateStreetTraffic;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agregar el tráfico de calles de un día: leer los pings, mapearlos a segmentos de calle vía
 * OSRM y acumular las muestras. Es el comando del worker de analítica (corre en segundo plano, nunca en un
 * request de usuario).
 *
 * - `vehicleId`: si viene, agrega solo ese vehículo; si es `null`, agrega todos los que tengan pings ese día.
 * - `date`: día a agregar en formato `YYYY-MM-DD`.
 */
final readonly class AggregateStreetTraffic implements Command
{
    public function __construct(
        public ?string $vehicleId,
        public string $date,
    ) {}
}
