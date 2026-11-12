<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListPreTripChecklists;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las inspecciones pre-salida como proyecciones de lectura, filtradas y ordenadas en SQL
 * (fecha desc, id desc). Con `limit` baja LIMIT/OFFSET a SQL y devuelve una Page con el total.
 *
 * @implements QueryHandler<ListPreTripChecklists, list<PreTripChecklistView>|Page<PreTripChecklistView>>
 */
final readonly class ListPreTripChecklistsHandler implements QueryHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
    ) {}

    /**
     * @return list<PreTripChecklistView>|Page<PreTripChecklistView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListPreTripChecklists);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (PreTripInspection $inspection): PreTripChecklistView => PreTripChecklistView::fromDomain($inspection),
            $this->inspections->listBy($query->vehicleId, $query->onlyPassed, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->inspections->countBy($query->vehicleId, $query->onlyPassed, $from, $to));
    }
}
