<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleExpenseTotal;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\ExpenseTotalView;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el total gastado por un vehículo en el rango pedido como proyección de lectura.
 *
 * @implements QueryHandler<GetVehicleExpenseTotal, ExpenseTotalView>
 */
final readonly class GetVehicleExpenseTotalHandler implements QueryHandler
{
    public function __construct(
        private ExpenseRepository $expenses,
    ) {}

    public function handle(Query $query): ExpenseTotalView
    {
        assert($query instanceof GetVehicleExpenseTotal);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $total = $this->expenses->totalFor($query->vehicleId, $from, $to);

        return ExpenseTotalView::of(
            $query->vehicleId,
            $total['cents'],
            $total['currency'],
            $query->from,
            $query->to,
        );
    }
}
