<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetDeparture;

use Logistics\FleetOps\Application\ReadModel\DepartureView;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una salida por su id, o null si no existe.
 *
 * @implements QueryHandler<GetDeparture, DepartureView|null>
 */
final readonly class GetDepartureHandler implements QueryHandler
{
    public function __construct(
        private DepartureRepository $departures,
    ) {}

    public function handle(Query $query): ?DepartureView
    {
        assert($query instanceof GetDeparture);

        $departure = $this->departures->findById(DepartureId::fromString($query->departureId));

        return $departure === null ? null : DepartureView::fromDomain($departure);
    }
}
