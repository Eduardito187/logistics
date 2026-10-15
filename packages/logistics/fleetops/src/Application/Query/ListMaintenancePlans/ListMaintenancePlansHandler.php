<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenancePlans;

use Logistics\FleetOps\Application\ReadModel\MaintenancePlanView;
use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los planes de mantenimiento como proyecciones de lectura. Con vehículo, solo los suyos.
 *
 * @implements QueryHandler<ListMaintenancePlans, list<MaintenancePlanView>>
 */
final readonly class ListMaintenancePlansHandler implements QueryHandler
{
    public function __construct(
        private MaintenancePlanRepository $plans,
    ) {}

    /**
     * @return list<MaintenancePlanView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListMaintenancePlans);

        $plans = $query->vehicleId !== null
            ? $this->plans->forVehicle($query->vehicleId)
            : $this->plans->all();

        return array_map(
            static fn (MaintenancePlan $plan): MaintenancePlanView => MaintenancePlanView::fromDomain($plan),
            $plans,
        );
    }
}
