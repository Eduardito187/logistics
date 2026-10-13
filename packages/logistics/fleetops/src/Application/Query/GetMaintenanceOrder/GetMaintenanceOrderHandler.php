<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetMaintenanceOrder;

use Logistics\FleetOps\Application\ReadModel\MaintenanceOrderView;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una orden de mantenimiento por su id, o null si no existe.
 *
 * @implements QueryHandler<GetMaintenanceOrder, MaintenanceOrderView|null>
 */
final readonly class GetMaintenanceOrderHandler implements QueryHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
    ) {}

    public function handle(Query $query): ?MaintenanceOrderView
    {
        assert($query instanceof GetMaintenanceOrder);

        $order = $this->orders->findById(MaintenanceOrderId::fromString($query->orderId));

        return $order === null ? null : MaintenanceOrderView::fromDomain($order);
    }
}
