<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetLatestPreTripChecklist;

use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el último checklist del vehículo como proyección de lectura, o null si nunca se inspeccionó.
 *
 * @implements QueryHandler<GetLatestPreTripChecklist, PreTripChecklistView|null>
 */
final readonly class GetLatestPreTripChecklistHandler implements QueryHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
    ) {}

    public function handle(Query $query): ?PreTripChecklistView
    {
        assert($query instanceof GetLatestPreTripChecklist);

        $inspection = $this->inspections->latestForVehicle($query->vehicleId);

        return $inspection !== null ? PreTripChecklistView::fromDomain($inspection) : null;
    }
}
