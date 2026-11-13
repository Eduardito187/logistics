<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetLatestPreTripChecklist;

use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Domain\Contract\FleetLabelReader;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el último checklist del vehículo como proyección de lectura, o null si nunca se inspeccionó.
 * Enriquece la vista con la placa del vehículo y el nombre del chofer (pueden ser null si ya no existen).
 *
 * @implements QueryHandler<GetLatestPreTripChecklist, PreTripChecklistView|null>
 */
final readonly class GetLatestPreTripChecklistHandler implements QueryHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
        private FleetLabelReader $labels,
    ) {}

    public function handle(Query $query): ?PreTripChecklistView
    {
        assert($query instanceof GetLatestPreTripChecklist);

        $inspection = $this->inspections->latestForVehicle($query->vehicleId);
        if ($inspection === null) {
            return null;
        }

        $vehicleId = $inspection->vehicleId()->toString();
        $driverId = $inspection->driverId()->toString();

        return PreTripChecklistView::fromDomain(
            $inspection,
            $this->labels->platesByVehicleId([$vehicleId])[$vehicleId] ?? null,
            $this->labels->namesByDriverId([$driverId])[$driverId] ?? null,
        );
    }
}
