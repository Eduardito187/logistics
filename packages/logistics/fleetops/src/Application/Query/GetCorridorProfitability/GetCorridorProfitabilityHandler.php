<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCorridorProfitability;

use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityLineView;
use Logistics\FleetOps\Application\ReadModel\CorridorProfitabilityView;
use Logistics\FleetOps\Application\Service\CorridorCostCalculator;
use Logistics\FleetOps\Domain\Service\ProfitabilityCalculator;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Rentabilidad por corredor (ROADMAP 3-06): reutiliza el costo por corredor (CorridorCostCalculator) y le
 * resta el ingreso para obtener el margen. El ingreso llega del query (inyectable por settings) o de la
 * configuración del contexto; si no hay ingreso para un corredor, su margen es null (listo para enchufar
 * el ingreso real cuando exista facturación — no se inventa ingreso).
 *
 * Fuente de ingreso por corredor (en orden): (1) el mapa explícito del query; (2) la clave del corredor en
 * `logistics-fleetops.revenue.by_corridor`; (3) `default_per_delivery_cents` × número de entregas, si está
 * configurado (> 0). Si nada aplica, el ingreso del corredor es null.
 *
 * @implements QueryHandler<GetCorridorProfitability, CorridorProfitabilityView>
 */
final readonly class GetCorridorProfitabilityHandler implements QueryHandler
{
    public function __construct(
        private CorridorCostCalculator $costs,
        private ProfitabilityCalculator $profitability = new ProfitabilityCalculator,
    ) {}

    public function handle(Query $query): CorridorProfitabilityView
    {
        assert($query instanceof GetCorridorProfitability);

        $rows = $this->costs->breakdown($query->from, $query->to);

        /** @var array<string, int> $byCorridor */
        $byCorridor = $query->revenueByCorridor ?? $this->configuredRevenueMap();
        $perDeliveryCents = $this->configuredPerDeliveryCents();

        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);
        $totalCostCents = 0;
        $totalRevenueCents = 0;
        $totalMarginCents = 0;
        $revenueAvailable = false;
        $lines = [];

        foreach ($rows as $row) {
            $currency = $row['currency'];
            $cost = $row['cost_cents'];
            $deliveries = $row['deliveries'];
            $totalCostCents += $cost;

            $revenue = $this->revenueFor($row['corridor'], $deliveries, $byCorridor, $perDeliveryCents);
            if ($revenue !== null) {
                $revenueAvailable = true;
                $totalRevenueCents += $revenue;
                $totalMarginCents += $revenue - $cost;
            }

            $lines[] = new CorridorProfitabilityLineView(
                $row['corridor'],
                $deliveries,
                $cost,
                $revenue,
                $this->profitability->marginCents($revenue, $cost),
                $this->profitability->marginRatio($revenue, $cost),
                $currency,
            );
        }

        return new CorridorProfitabilityView(
            $query->from,
            $query->to,
            $totalCostCents,
            $revenueAvailable ? $totalRevenueCents : null,
            $revenueAvailable ? $totalMarginCents : null,
            $currency,
            $revenueAvailable,
            $lines,
        );
    }

    /**
     * Ingreso de un corredor según las fuentes configurables, o null si no hay ingreso disponible.
     *
     * @param array<string, int> $byCorridor
     */
    private function revenueFor(string $corridor, int $deliveries, array $byCorridor, int $perDeliveryCents): ?int
    {
        if (array_key_exists($corridor, $byCorridor)) {
            return $byCorridor[$corridor];
        }

        if ($perDeliveryCents > 0) {
            return $perDeliveryCents * $deliveries;
        }

        return null;
    }

    /**
     * Mapa corredor → ingreso (centavos) de la configuración; solo claves string con valor entero.
     *
     * @return array<string, int>
     */
    private function configuredRevenueMap(): array
    {
        $configured = config('logistics-fleetops.revenue.by_corridor', []);
        if (!is_array($configured)) {
            return [];
        }

        $map = [];
        foreach ($configured as $corridor => $cents) {
            if (is_string($corridor) && is_int($cents)) {
                $map[$corridor] = $cents;
            }
        }

        return $map;
    }

    /**
     * Ingreso por entrega por defecto (centavos) de la configuración; 0 si no está configurado.
     */
    private function configuredPerDeliveryCents(): int
    {
        $value = config('logistics-fleetops.revenue.default_per_delivery_cents', 0);

        return is_int($value) ? $value : 0;
    }
}
