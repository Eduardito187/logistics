<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleTrack;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\PingView;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Devuelve la traza de un vehículo entre dos instantes como lista de puntos de lectura.
 *
 * @implements QueryHandler<GetVehicleTrack, list<PingView>>
 */
final readonly class GetVehicleTrackHandler implements QueryHandler
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    /**
     * @return list<PingView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetVehicleTrack);

        $track = $this->pings->track(
            $query->vehicleId,
            new DateTimeImmutable($query->from),
            new DateTimeImmutable($query->to),
        );

        return array_map(
            static fn (VehiclePing $ping): PingView => PingView::fromDomain($ping),
            $track,
        );
    }
}
