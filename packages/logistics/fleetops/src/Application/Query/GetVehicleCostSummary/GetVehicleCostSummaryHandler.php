<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleCostSummary;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\ReadModel\VehicleCostSummaryView;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Throwable;

/**
 * Compone el costo total de operación de un vehículo (combustible + mantenimiento + gastos) y el costo
 * por km del período. Es un query TRANSVERSAL: lee los tres repos del contexto y los km recorridos de
 * telemetría (lectura read-only de `telemetry_trips`, monorepo; degrada a 0 km si la tabla no existe).
 *
 * @implements QueryHandler<GetVehicleCostSummary, VehicleCostSummaryView>
 */
final readonly class GetVehicleCostSummaryHandler implements QueryHandler
{
    public function __construct(
        private RefuelRepository $refuels,
        private MaintenanceOrderRepository $orders,
        private ExpenseRepository $expenses,
    ) {}

    public function handle(Query $query): VehicleCostSummaryView
    {
        assert($query instanceof GetVehicleCostSummary);

        $from = new DateTimeImmutable($query->from.' 00:00:00');
        $to = new DateTimeImmutable($query->to.' 23:59:59');
        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);

        // Combustible: suma de las cargas del período.
        $fuelCents = 0;
        foreach ($this->refuels->listBy($query->vehicleId, $from, $to) as $refuel) {
            assert($refuel instanceof Refuel);
            $fuelCents += $refuel->totalCost()->cents;
            $currency = $refuel->totalCost()->currency;
        }

        // Mantenimiento: órdenes completadas cuyo cierre cae en el período.
        $maintenanceCents = 0;
        foreach ($this->orders->listBy($query->vehicleId, MaintenanceStatus::Completed->value) as $order) {
            assert($order instanceof MaintenanceOrder);
            $completedAt = $order->completedAt();
            if ($completedAt !== null && $completedAt >= $from && $completedAt <= $to) {
                $maintenanceCents += $order->totalCost()->cents;
            }
        }

        // Gastos varios (peajes, multas, llantas…).
        $expenseTotal = $this->expenses->totalFor($query->vehicleId, $from, $to);
        $expensesCents = $expenseTotal['cents'];

        $kilometers = $this->kilometersFromTelemetry($query->vehicleId, $query->from, $query->to);
        $totalCents = $fuelCents + $maintenanceCents + $expensesCents;
        $costPerKmCents = $kilometers > 0 ? (int) round($totalCents / $kilometers) : null;

        return new VehicleCostSummaryView(
            $query->vehicleId,
            $query->from,
            $query->to,
            $fuelCents,
            $maintenanceCents,
            $expensesCents,
            $totalCents,
            $currency,
            $kilometers,
            $costPerKmCents,
        );
    }

    /**
     * Km recorridos en el período según `telemetry_trips.distance_m` (metros → km). Si telemetría no
     * está presente o falla, devuelve 0 (el costo por km queda null).
     */
    private function kilometersFromTelemetry(string $vehicleId, string $from, string $to): int
    {
        try {
            $meters = (float) DB::table('telemetry_trips')
                ->where('vehicle_id', $vehicleId)
                ->whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->sum('distance_m');

            return (int) round($meters / 1000);
        } catch (Throwable) {
            return 0;
        }
    }
}
