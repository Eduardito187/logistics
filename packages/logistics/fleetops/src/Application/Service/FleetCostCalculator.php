<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Service;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\Money;

/**
 * Pieza de aplicación reutilizable que compone el costo operativo de un vehículo en un período:
 * combustible (cargas del rango) + mantenimiento (órdenes completadas cuyo cierre cae en el rango) +
 * gastos varios. Es el MISMO criterio de GetVehicleCostSummaryHandler, extraído para que las lecturas de
 * costo por vehículo/corredor/tercero (ROADMAP 3-01) no dupliquen la lógica.
 *
 * Devuelve primitivos (centavos + moneda); el costo por km/entrega lo deriva el CostAllocator del dominio.
 */
final readonly class FleetCostCalculator
{
    public function __construct(
        private RefuelRepository $refuels,
        private MaintenanceOrderRepository $orders,
        private ExpenseRepository $expenses,
    ) {}

    /**
     * Totales de costo de un vehículo en el rango. La moneda se toma de los importes encontrados; si no
     * hay movimientos, cae a la moneda por defecto del contexto.
     *
     * @return array{fuel_cents: int, maintenance_cents: int, expenses_cents: int, total_cents: int, currency: string}
     */
    public function vehicleTotals(string $vehicleId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);

        // Combustible: suma de las cargas del período.
        $fuelCents = 0;
        foreach ($this->refuels->listBy($vehicleId, $from, $to) as $refuel) {
            assert($refuel instanceof Refuel);
            $fuelCents += $refuel->totalCost()->cents;
            $currency = $refuel->totalCost()->currency;
        }

        // Mantenimiento: órdenes completadas cuyo cierre cae en el período.
        $maintenanceCents = 0;
        foreach ($this->orders->listBy($vehicleId, MaintenanceStatus::Completed->value) as $order) {
            assert($order instanceof MaintenanceOrder);
            $completedAt = $order->completedAt();
            if ($completedAt !== null && $completedAt >= $from && $completedAt <= $to) {
                $maintenanceCents += $order->totalCost()->cents;
            }
        }

        // Gastos varios (peajes, multas, llantas…).
        $expenseTotal = $this->expenses->totalFor($vehicleId, $from, $to);
        $expensesCents = $expenseTotal['cents'];

        return [
            'fuel_cents' => $fuelCents,
            'maintenance_cents' => $maintenanceCents,
            'expenses_cents' => $expensesCents,
            'total_cents' => $fuelCents + $maintenanceCents + $expensesCents,
            'currency' => $currency,
        ];
    }
}
