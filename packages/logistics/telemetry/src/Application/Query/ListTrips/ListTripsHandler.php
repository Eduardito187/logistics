<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListTrips;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\TripView;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Model\Trip;

/**
 * Devuelve la lista de trips (con filtro opcional por vehículo y día) como proyecciones de lectura.
 *
 * @implements QueryHandler<ListTrips, list<TripView>>
 */
final readonly class ListTripsHandler implements QueryHandler
{
    public function __construct(
        private TripRepository $trips,
    ) {}

    /**
     * @return list<TripView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListTrips);

        $day = $query->date === null ? null : new DateTimeImmutable($query->date);

        $trips = $this->trips->listBy($query->vehicleId, $day);

        return array_map(
            static fn (Trip $trip): TripView => TripView::fromDomain($trip),
            $trips,
        );
    }
}
