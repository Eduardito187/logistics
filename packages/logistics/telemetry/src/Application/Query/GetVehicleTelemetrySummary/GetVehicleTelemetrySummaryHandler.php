<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleTelemetrySummary;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\VehicleSummaryView;
use Logistics\Telemetry\Domain\Contract\TripRepository;

/**
 * Resume la telemetría de un vehículo en un día agregando sus trips: distancia total, tiempo en
 * movimiento vs detenido, cantidad de trips, velocidad máxima y la primera/última lectura.
 *
 * @implements QueryHandler<GetVehicleTelemetrySummary, VehicleSummaryView>
 */
final readonly class GetVehicleTelemetrySummaryHandler implements QueryHandler
{
    public function __construct(
        private TripRepository $trips,
    ) {}

    public function handle(Query $query): VehicleSummaryView
    {
        assert($query instanceof GetVehicleTelemetrySummary);

        $day = new DateTimeImmutable($query->date);
        $trips = $this->trips->listBy($query->vehicleId, $day);

        $distanceM = 0.0;
        $movingS = 0;
        $idleS = 0;
        $maxSpeedKph = 0.0;
        $first = null;
        $last = null;

        foreach ($trips as $trip) {
            $distanceM += $trip->distanceM();
            $movingS += $trip->movingS();
            $idleS += $trip->idleS();
            $maxSpeedKph = max($maxSpeedKph, $trip->maxSpeedKph());

            $start = $trip->startedAt();
            $end = $trip->endedAt() ?? $trip->lastPointAt();

            if ($first === null || $start < $first) {
                $first = $start;
            }
            if ($last === null || $end > $last) {
                $last = $end;
            }
        }

        return new VehicleSummaryView(
            $query->vehicleId,
            $day->format('Y-m-d'),
            $distanceM,
            $movingS,
            $idleS,
            count($trips),
            $maxSpeedKph,
            $first?->format(DATE_ATOM),
            $last?->format(DATE_ATOM),
        );
    }
}
