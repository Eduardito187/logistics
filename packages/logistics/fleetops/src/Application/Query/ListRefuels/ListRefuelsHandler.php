<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListRefuels;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\RefuelView;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las cargas de combustible filtradas como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa, por compatibilidad.
 *
 * @implements QueryHandler<ListRefuels, list<RefuelView>|Page<RefuelView>>
 */
final readonly class ListRefuelsHandler implements QueryHandler
{
    public function __construct(
        private RefuelRepository $refuels,
    ) {}

    /**
     * @return list<RefuelView>|Page<RefuelView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListRefuels);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (Refuel $refuel): RefuelView => RefuelView::fromDomain($refuel),
            $this->refuels->listBy($query->vehicleId, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->refuels->countBy($query->vehicleId, $from, $to));
    }
}
