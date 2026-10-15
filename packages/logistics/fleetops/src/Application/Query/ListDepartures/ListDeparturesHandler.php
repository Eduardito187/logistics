<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDepartures;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las salidas que cumplen los filtros como proyecciones de lectura.
 *
 * @implements QueryHandler<ListDepartures, list<DepartureView>>
 */
final readonly class ListDeparturesHandler implements QueryHandler
{
    public function __construct(
        private DepartureRepository $departures,
    ) {}

    /**
     * @return list<DepartureView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListDepartures);

        $departures = $this->departures->listBy(
            $query->vehicleId,
            $query->status,
            $query->from !== null ? new DateTimeImmutable($query->from) : null,
            $query->to !== null ? new DateTimeImmutable($query->to) : null,
        );

        return array_map(
            static fn (Departure $departure): DepartureView => DepartureView::fromDomain($departure),
            $departures,
        );
    }
}
