<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListRefuels;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\RefuelView;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las cargas de combustible filtradas como proyecciones de lectura.
 *
 * @implements QueryHandler<ListRefuels, list<RefuelView>>
 */
final readonly class ListRefuelsHandler implements QueryHandler
{
    public function __construct(
        private RefuelRepository $refuels,
    ) {}

    /**
     * @return list<RefuelView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListRefuels);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $refuels = $this->refuels->listBy($query->vehicleId, $from, $to);

        return array_map(static fn (Refuel $refuel): RefuelView => RefuelView::fromDomain($refuel), $refuels);
    }
}
