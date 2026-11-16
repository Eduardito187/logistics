<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RefreshVehicleEtas;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Application\Service\OffRouteEvaluator;
use Logistics\Telemetry\Application\Service\RerouteGeometryCalculator;
use Logistics\Telemetry\Application\Service\StopEtaCalculator;
use Logistics\Telemetry\Domain\Contract\RoadDurationEstimator;
use Logistics\Telemetry\Domain\Contract\RoadGeometryEstimator;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;

/**
 * Recalcula la ETA de las paradas restantes del vehículo y reemplaza su proyección `telemetry_stop_etas`.
 *
 * Usa el estimador vial (OSRM, sin tráfico en vivo) si está configurado; si no, o si falla, el respaldo en
 * línea recta. Posición vieja o sin paradas → la proyección del vehículo queda vacía (no se muestran ETAs
 * inventadas). Es una proyección de lectura: no emite eventos de dominio.
 *
 * Además, cuando el vehículo está FUERA de ruta, precalcula con OSRM la geometría del reruteo (posición actual
 * → próxima parada) y la cachea, para que la lectura la devuelva sin pegarle a OSRM; si no lo está, limpia esa
 * caché. Es el ÚNICO lugar donde se consulta OSRM para el reruteo (fuera de cualquier request).
 *
 * @implements CommandHandler<RefreshVehicleEtas>
 */
final readonly class RefreshVehicleEtasHandler implements CommandHandler
{
    public function __construct(
        private StopEtaCalculator $calculator,
        private RoadDurationEstimator $road,
        private StopEtaRepository $etas,
        private RoadGeometryEstimator $roadGeometry,
        private RerouteGeometryCalculator $reroute,
        private VehiclePositionRepository $positions,
        private OffRouteEvaluator $offRoute,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RefreshVehicleEtas);

        $result = $this->calculator->calculate($command->vehicleId, $this->road);

        $this->etas->replaceForVehicle($command->vehicleId, $result['etas']);

        // Geometría del reruteo: pesada (OSRM), solo acá. Se cachea cuando está fuera de ruta; si no, se limpia.
        $since = $this->positions->watchState($command->vehicleId)['off_route_since'];
        [$offRoute] = $this->offRoute->evaluate($since, $result['stale']);
        $position = $result['position'];

        $this->reroute->refresh(
            $command->vehicleId,
            $offRoute,
            $position === null ? null : ['lat' => $position['lat'], 'lng' => $position['lng']],
            RerouteGeometryCalculator::firstStopWithCoords($result['stops']),
            $this->roadGeometry,
        );
    }
}
