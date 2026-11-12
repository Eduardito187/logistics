<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenanceOrders;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las órdenes de mantenimiento como proyecciones de lectura. Con `limit` baja LIMIT/OFFSET a SQL
 * y devuelve una Page (ítems + total).
 *
 * @implements QueryHandler<ListMaintenanceOrders, list<MaintenanceOrderView>|Page<MaintenanceOrderView>>
 */
final readonly class ListMaintenanceOrdersHandler implements QueryHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
    ) {}

    /**
     * @return list<MaintenanceOrderView>|Page<MaintenanceOrderView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListMaintenanceOrders);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (MaintenanceOrder $order): MaintenanceOrderView => MaintenanceOrderView::fromDomain($order),
            $this->orders->listBy($query->vehicleId, $query->status, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->orders->countBy($query->vehicleId, $query->status, $from, $to));
    }
}
