<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDueMaintenance;

use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Application\Service\DueMaintenanceScanner;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Devuelve los mantenimientos por vencer/vencidos a hoy, con el mismo criterio que la alerta, más
 * urgentes primero. Filtra opcionalmente por vehículo y por urgencia.
 *
 * @implements QueryHandler<ListDueMaintenance, list<DueMaintenanceView>>
 */
final readonly class ListDueMaintenanceHandler implements QueryHandler
{
    public function __construct(
        private DueMaintenanceScanner $scanner,
        private Clock $clock,
    ) {}

    /**
     * @return list<DueMaintenanceView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListDueMaintenance);

        $due = $this->scanner->scan(
            $this->clock->now(),
            (int) config('logistics-fleetops.maintenance.due_soon_km', 500),
            (int) config('logistics-fleetops.maintenance.due_soon_days', 7),
        );

        return array_values(array_filter(
            $due,
            static fn (DueMaintenanceView $view): bool => ($query->vehicleId === null || $view->vehicleId === $query->vehicleId)
                && ($query->urgency === null || $view->urgency() === $query->urgency),
        ));
    }
}
