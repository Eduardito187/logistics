<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RefreshVehicleEtas;

use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Application\Service\StopEtaCalculator;
use Logistics\Telemetry\Domain\Contract\RoadDurationEstimator;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;

/**
 * Recalcula la ETA de las paradas restantes del vehículo y reemplaza su proyección `telemetry_stop_etas`.
 *
 * Usa el estimador vial (OSRM, sin tráfico en vivo) si está configurado; si no, o si falla, el respaldo en
 * línea recta. Posición vieja o sin paradas → la proyección del vehículo queda vacía (no se muestran ETAs
 * inventadas). Es una proyección de lectura: no emite eventos de dominio.
 *
 * @implements CommandHandler<RefreshVehicleEtas>
 */
final readonly class RefreshVehicleEtasHandler implements CommandHandler
{
    public function __construct(
        private StopEtaCalculator $calculator,
        private RoadDurationEstimator $road,
        private StopEtaRepository $etas,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RefreshVehicleEtas);

        $result = $this->calculator->calculate($command->vehicleId, $this->road);

        $this->etas->replaceForVehicle($command->vehicleId, $result['etas']);
    }
}
