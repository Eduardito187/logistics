<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Telemetry\Application\Scope\FleetLiveScope;

/**
 * Pide la posición en vivo de la flota (la última lectura conocida de cada vehículo) dentro de un alcance.
 *
 * `since` (opcional): solo los vehículos cuya posición cambió (hora del servidor) después de ese instante,
 * para el polling incremental. `enrich` (por defecto sí): resolver placa y chofer en lote. `scope` (por
 * defecto sin restricción): qué camiones puede ver quien consulta, según su rol y su ciudad.
 *
 * Los vehículos dados de baja en flota nunca salen, sea cual sea el alcance.
 */
final readonly class GetLiveFleetPositions implements Query
{
    public FleetLiveScope $scope;

    public function __construct(
        public ?DateTimeImmutable $since = null,
        public bool $enrich = true,
        ?FleetLiveScope $scope = null,
    ) {
        $this->scope = $scope ?? FleetLiveScope::unrestricted();
    }
}
