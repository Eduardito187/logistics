<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListPreTripChecklists;

use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de inspecciones pre-salida como proyecciones de lectura.
 *
 * @implements QueryHandler<ListPreTripChecklists, list<PreTripChecklistView>>
 */
final readonly class ListPreTripChecklistsHandler implements QueryHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
    ) {}

    /**
     * @return list<PreTripChecklistView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPreTripChecklists);

        $inspections = $this->inspections->all();

        if ($query->vehicleId !== null) {
            $inspections = array_values(array_filter(
                $inspections,
                static fn (PreTripInspection $inspection): bool => $inspection->vehicleId()->toString() === $query->vehicleId,
            ));
        }

        if ($query->onlyPassed !== null) {
            $inspections = array_values(array_filter(
                $inspections,
                static fn (PreTripInspection $inspection): bool => $inspection->passed() === $query->onlyPassed,
            ));
        }

        return array_map(
            static fn (PreTripInspection $inspection): PreTripChecklistView => PreTripChecklistView::fromDomain($inspection),
            $inspections,
        );
    }
}
