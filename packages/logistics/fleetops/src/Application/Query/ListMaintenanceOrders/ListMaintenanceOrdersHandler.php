<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenanceOrders;

use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las órdenes de mantenimiento como proyecciones de lectura.
 *
 * @implements QueryHandler<ListMaintenanceOrders, list<MaintenanceOrderView>>
 */
final readonly class ListMaintenanceOrdersHandler implements QueryHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
    ) {}

    /**
     * @return list<MaintenanceOrderView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListMaintenanceOrders);

        $orders = $this->orders->listBy($query->vehicleId, $query->status);

        return array_map(
            static fn (MaintenanceOrder $order): MaintenanceOrderView => MaintenanceOrderView::fromDomain($order),
            $orders,
        );
    }
}
