<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetDailyFuelSpend;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\DailyFuelSpendView;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Suma el gasto de combustible de un día (00:00:00 a 23:59:59): total del día y desglose por vehículo.
 *
 * @implements QueryHandler<GetDailyFuelSpend, DailyFuelSpendView>
 */
final readonly class GetDailyFuelSpendHandler implements QueryHandler
{
    public function __construct(
        private RefuelRepository $refuels,
    ) {}

    public function handle(Query $query): DailyFuelSpendView
    {
        assert($query instanceof GetDailyFuelSpend);

        $start = new DateTimeImmutable($query->date.' 00:00:00');
        $end = new DateTimeImmutable($query->date.' 23:59:59');

        $refuels = $this->refuels->listBy(null, $start, $end);

        $totalCents = 0;
        $currency = Money::DEFAULT_CURRENCY;

        // Acumuladores por vehículo: costo en centavos y litros.
        /** @var array<string, array{total_cost_cents: int, liters: float}> $perVehicle */
        $perVehicle = [];

        foreach ($refuels as $refuel) {
            $vehicleId = $refuel->vehicleId();
            $perVehicle[$vehicleId] ??= ['total_cost_cents' => 0, 'liters' => 0.0];
            $perVehicle[$vehicleId]['total_cost_cents'] += $refuel->totalCost()->cents;
            $perVehicle[$vehicleId]['liters'] += $refuel->liters();

            $totalCents += $refuel->totalCost()->cents;
            $currency = $refuel->totalCost()->currency;
        }

        $byVehicle = [];
        foreach ($perVehicle as $vehicleId => $totals) {
            $byVehicle[] = [
                'vehicle_id' => $vehicleId,
                'total_cost_cents' => $totals['total_cost_cents'],
                'liters' => round($totals['liters'], 2),
            ];
        }

        return new DailyFuelSpendView(
            $query->date,
            $totalCents,
            $currency,
            $byVehicle,
        );
    }
}
