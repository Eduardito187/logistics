<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCorridorCostBreakdown;

use Logistics\FleetOps\Application\ReadModel\CorridorCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CorridorCostLineView;
use Logistics\FleetOps\Application\Service\CorridorCostCalculator;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Desglose de costo de la flota propia por corredor (ROADMAP 3-01). Delega la atribución al
 * CorridorCostCalculator (compartido con la lectura de rentabilidad) y la envuelve en el read-model.
 *
 * @implements QueryHandler<GetCorridorCostBreakdown, CorridorCostBreakdownView>
 */
final readonly class GetCorridorCostBreakdownHandler implements QueryHandler
{
    public function __construct(
        private CorridorCostCalculator $calculator,
    ) {}

    public function handle(Query $query): CorridorCostBreakdownView
    {
        assert($query instanceof GetCorridorCostBreakdown);

        $rows = $this->calculator->breakdown($query->from, $query->to);

        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);
        $totalCents = 0;
        $lines = [];

        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totalCents += $row['cost_cents'];
            $lines[] = new CorridorCostLineView(
                $row['corridor'],
                $row['departures'],
                $row['kilometers'],
                $row['deliveries'],
                $row['cost_cents'],
                $row['cost_per_km_cents'],
                $row['cost_per_delivery_cents'],
                $row['currency'],
            );
        }

        return new CorridorCostBreakdownView($query->from, $query->to, $totalCents, $currency, $lines);
    }
}
