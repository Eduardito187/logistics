<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetMaintenanceCost;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\MaintenanceCostView;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Acumula el costo de las órdenes de mantenimiento completadas de un vehículo dentro de un rango de
 * fechas (por su fecha de cierre). Suma en Money para respetar la moneda; si no hay órdenes, da cero BOB.
 *
 * @implements QueryHandler<GetMaintenanceCost, MaintenanceCostView>
 */
final readonly class GetMaintenanceCostHandler implements QueryHandler
{
    public function __construct(
        private MaintenanceOrderRepository $orders,
    ) {}

    public function handle(Query $query): MaintenanceCostView
    {
        assert($query instanceof GetMaintenanceCost);

        $from = new DateTimeImmutable($query->from);
        $to = new DateTimeImmutable($query->to);

        $orders = $this->orders->listBy($query->vehicleId, MaintenanceStatus::Completed->value);

        $total = Money::zero();
        $count = 0;
        foreach ($orders as $order) {
            if (!$this->completedWithin($order, $from, $to)) {
                continue;
            }
            $total = $total->add($order->totalCost());
            $count++;
        }

        return MaintenanceCostView::of(
            $query->vehicleId,
            $from->format(DATE_ATOM),
            $to->format(DATE_ATOM),
            $count,
            $total->cents,
            $total->currency,
        );
    }

    private function completedWithin(MaintenanceOrder $order, DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        $completedAt = $order->completedAt();

        return $completedAt !== null && $completedAt >= $from && $completedAt <= $to;
    }
}
