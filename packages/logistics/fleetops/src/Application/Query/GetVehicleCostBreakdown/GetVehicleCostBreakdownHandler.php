<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetVehicleCostBreakdown;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\ReadModel\VehicleCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\VehicleCostLineView;
use Logistics\FleetOps\Application\Service\FleetCostCalculator;
use Logistics\FleetOps\Application\Service\TelemetryDistanceReader;
use Logistics\FleetOps\Domain\Service\CostAllocator;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Throwable;

/**
 * Desglose de costo de la flota por vehículo (ROADMAP 3-01). Para cada vehículo activo suma combustible +
 * mantenimiento + gastos (FleetCostCalculator) y divide por los km de telemetría para el costo por km.
 *
 * La lista de vehículos se toma de `fleet_vehicles` (lectura cruzada read-only del contexto fleet, igual
 * que el cockpit); si esa tabla no está presente, el desglose sale vacío sin romper.
 *
 * @implements QueryHandler<GetVehicleCostBreakdown, VehicleCostBreakdownView>
 */
final readonly class GetVehicleCostBreakdownHandler implements QueryHandler
{
    public function __construct(
        private FleetCostCalculator $costs,
        private TelemetryDistanceReader $telemetry,
        private CostAllocator $allocator = new CostAllocator,
    ) {}

    public function handle(Query $query): VehicleCostBreakdownView
    {
        assert($query instanceof GetVehicleCostBreakdown);

        $fromAt = new DateTimeImmutable($query->from.' 00:00:00');
        $toAt = new DateTimeImmutable($query->to.' 23:59:59');

        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);
        $kmByVehicle = $this->telemetry->kmByVehicle($query->from, $query->to);

        $lines = [];
        $totalCents = 0;

        foreach ($this->vehicleIds() as $vehicleId) {
            $totals = $this->costs->vehicleTotals($vehicleId, $fromAt, $toAt);

            // Vehículos sin ningún costo en el rango no aportan ruido al desglose.
            if ($totals['total_cents'] === 0) {
                continue;
            }

            $currency = $totals['currency'];
            $km = $kmByVehicle[$vehicleId] ?? 0;
            $totalCents += $totals['total_cents'];

            $lines[] = new VehicleCostLineView(
                $vehicleId,
                $totals['fuel_cents'],
                $totals['maintenance_cents'],
                $totals['expenses_cents'],
                $totals['total_cents'],
                $km,
                $this->allocator->costPerKmCents($totals['total_cents'], $km),
                $totals['currency'],
            );
        }

        usort($lines, static fn (VehicleCostLineView $a, VehicleCostLineView $b): int => $b->totalCents <=> $a->totalCents);

        return new VehicleCostBreakdownView($query->from, $query->to, $totalCents, $currency, $lines);
    }

    /**
     * Ids de vehículos de flota (read-only cross-context). Degrada a lista vacía si la tabla no existe.
     *
     * @return list<string>
     */
    private function vehicleIds(): array
    {
        try {
            /** @var list<string> $ids */
            $ids = DB::table('fleet_vehicles')
                ->orderBy('plate')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            return $ids;
        } catch (Throwable) {
            return [];
        }
    }
}
