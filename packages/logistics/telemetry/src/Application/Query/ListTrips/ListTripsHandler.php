<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListTrips;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\ReadModel\TripView;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Model\Trip;

/**
 * Devuelve la lista de trips (con filtro opcional por vehículo y día) como proyecciones de lectura.
 *
 * Con `limit` (lo setea el controller al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems +
 * total). Sin `limit`, devuelve la lista completa (compat con consumidores que aún no paginan).
 *
 * @implements QueryHandler<ListTrips, list<TripView>|Page<TripView>>
 */
final readonly class ListTripsHandler implements QueryHandler
{
    public function __construct(
        private TripRepository $trips,
    ) {}

    /**
     * @return list<TripView>|Page<TripView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListTrips);

        $day = $query->date === null ? null : new DateTimeImmutable($query->date);

        $views = array_map(
            static fn (Trip $trip): TripView => TripView::fromDomain($trip),
            $this->trips->listBy($query->vehicleId, $day, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->trips->countBy($query->vehicleId, $day));
    }
}
