<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDepartures;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las salidas que cumplen los filtros como proyecciones de lectura. Con `limit` baja
 * LIMIT/OFFSET a SQL y devuelve una Page (ítems + total).
 *
 * @implements QueryHandler<ListDepartures, list<DepartureView>|Page<DepartureView>>
 */
final readonly class ListDeparturesHandler implements QueryHandler
{
    public function __construct(
        private DepartureRepository $departures,
    ) {}

    /**
     * @return list<DepartureView>|Page<DepartureView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListDepartures);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (Departure $departure): DepartureView => DepartureView::fromDomain($departure),
            $this->departures->listBy($query->vehicleId, $query->status, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->departures->countBy($query->vehicleId, $query->status, $from, $to));
    }
}
