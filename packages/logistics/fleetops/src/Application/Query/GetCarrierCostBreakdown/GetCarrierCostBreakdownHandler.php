<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetCarrierCostBreakdown;

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\ReadModel\CarrierCostBreakdownView;
use Logistics\FleetOps\Application\ReadModel\CarrierCostLineView;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Throwable;

/**
 * Desglose de costo de TERCEROS (transportistas) por período (ROADMAP 3-01, lado de terceros). Lee las
 * liquidaciones del contexto carriers (`carrier_settlements`) EN LOTE y las agrupa por transportista.
 *
 * Es una lectura CRUZADA read-only del monorepo (no una dependencia composer): fleetops no depende de
 * carriers. Si carriers no está presente en este entorno, degrada a una lista vacía sin romper.
 *
 * Supuesto (documentado): el costo de un tercero es lo LIQUIDADO en el período. El período de la
 * liquidación es mensual ('YYYY-MM'); se incluye una liquidación si su período cae dentro del rango de
 * meses [from..to]. Se excluyen las liquidaciones objetadas ('disputed') porque aún no son costo firme.
 *
 * @implements QueryHandler<GetCarrierCostBreakdown, CarrierCostBreakdownView>
 */
final readonly class GetCarrierCostBreakdownHandler implements QueryHandler
{
    public function handle(Query $query): CarrierCostBreakdownView
    {
        assert($query instanceof GetCarrierCostBreakdown);

        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);
        $fromPeriod = substr($query->from, 0, 7);
        $toPeriod = substr($query->to, 0, 7);

        /** @var array<string, array{carrier_id: string, settlements: int, corridors: array<string, true>, cost_cents: int, currency: string}> $acc */
        $acc = [];
        $totalCents = 0;

        try {
            /** @var list<\stdClass> $rows */
            $rows = DB::table('carrier_settlements')
                ->whereBetween('period', [$fromPeriod, $toPeriod])
                ->where('status', '!=', 'disputed')
                ->get(['carrier_id', 'corridor_from', 'corridor_to', 'corridor_type', 'amount_cents', 'currency'])
                ->all();
        } catch (Throwable) {
            return new CarrierCostBreakdownView($query->from, $query->to, 0, $currency, []);
        }

        foreach ($rows as $row) {
            $carrierId = (string) $row->carrier_id;
            $cents = (int) $row->amount_cents;
            $corridorKey = (string) $row->corridor_from.'→'.(string) $row->corridor_to.'·'.(string) $row->corridor_type;

            if (!isset($acc[$carrierId])) {
                $acc[$carrierId] = [
                    'carrier_id' => $carrierId,
                    'settlements' => 0,
                    'corridors' => [],
                    'cost_cents' => 0,
                    'currency' => is_string($row->currency) ? $row->currency : $currency,
                ];
            }

            $acc[$carrierId]['settlements']++;
            $acc[$carrierId]['corridors'][$corridorKey] = true;
            $acc[$carrierId]['cost_cents'] += $cents;
            $totalCents += $cents;
        }

        $names = $this->carrierNames(array_keys($acc));

        $lines = [];
        foreach ($acc as $row) {
            $lines[] = new CarrierCostLineView(
                $row['carrier_id'],
                $names[$row['carrier_id']] ?? null,
                $row['settlements'],
                count($row['corridors']),
                $row['cost_cents'],
                $row['currency'],
            );
        }

        usort($lines, static fn (CarrierCostLineView $a, CarrierCostLineView $b): int => $b->costCents <=> $a->costCents);

        return new CarrierCostBreakdownView($query->from, $query->to, $totalCents, $currency, $lines);
    }

    /**
     * Nombres de los transportistas EN LOTE (id → name), read-only desde `carriers`. Vacío si falla.
     *
     * @param  list<string>          $carrierIds
     * @return array<string, string>
     */
    private function carrierNames(array $carrierIds): array
    {
        if ($carrierIds === []) {
            return [];
        }

        try {
            /** @var array<string, string> $names */
            $names = DB::table('carriers')
                ->whereIn('id', $carrierIds)
                ->pluck('name', 'id')
                ->map(static fn ($name): string => (string) $name)
                ->all();

            return $names;
        } catch (Throwable) {
            return [];
        }
    }
}
