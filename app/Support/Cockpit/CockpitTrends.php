<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tendencias del cockpit (Fase 5 — "datos que se ven"): series diarias derivadas REALES para los gráficos
 * de la Torre de Control. Deriva de las tablas existentes (sin tabla de histórico): envíos recibidos por
 * día (shipments.created_at) y entregas confirmadas por día (execution_deliveries DELIVERED). Rellena los
 * días sin dato con 0 para que el eje sea continuo. SOLO lectura, tolerante y cacheado corto (hereda el
 * caché del cockpit). Devuelve primitivos listos para <x-cockpit.chart>.
 */
final class CockpitTrends
{
    use ReadsCockpitAggregates;

    /** Días hacia atrás de las series. */
    private const DAYS = 14;

    /**
     * Envíos recibidos por día (últimos 14 días).
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function receivedPerDay(): array
    {
        return $this->remember('trends:received', fn (): array => $this->dailySeries(
            fn (): array => DB::table('shipments')
                ->where('created_at', '>=', $this->startOfToday()->subDays(self::DAYS - 1))
                ->selectRaw('date(created_at) as d, count(*) as c')
                ->groupByRaw('date(created_at)')
                ->pluck('c', 'd')
                ->map(static fn ($c): int => (int) $c)
                ->all(),
        ));
    }

    /**
     * Entregas confirmadas por día (últimos 14 días).
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function deliveredPerDay(): array
    {
        return $this->remember('trends:delivered', fn (): array => $this->dailySeries(
            fn (): array => DB::table('execution_deliveries')
                ->where('status', 'DELIVERED')
                ->whereNotNull('pod_captured_at')
                ->where('pod_captured_at', '>=', $this->startOfToday()->subDays(self::DAYS - 1))
                ->selectRaw('date(pod_captured_at) as d, count(*) as c')
                ->groupByRaw('date(pod_captured_at)')
                ->pluck('c', 'd')
                ->map(static fn ($c): int => (int) $c)
                ->all(),
        ));
    }

    /**
     * Distribución actual de envíos por estado del ciclo de vida (para un gráfico de barras), en el orden
     * del flujo. Siempre devuelve las 5 filas (con 0 si no hay), con su tono para el color.
     *
     * @return list<array{label: string, count: int, tone: string}>
     */
    public function statusDistribution(): array
    {
        $labels = [
            'RECEIVED' => ['Recibidos', 'agent'],
            'PLANNED' => ['Planificados', 'agent'],
            'IN_EXECUTION' => ['En ejecución', 'amber'],
            'DELIVERED' => ['Entregados', 'green'],
            'FAILED' => ['Fallidos', 'red'],
        ];

        $counts = $this->remember('trends:status', function (): array {
            try {
                /** @var array<string, int> $c */
                $c = DB::table('shipments')
                    ->selectRaw('status, count(*) as c')
                    ->groupBy('status')
                    ->pluck('c', 'status')
                    ->map(static fn ($v): int => (int) $v)
                    ->all();

                return $c;
            } catch (Throwable) {
                return [];
            }
        });

        $out = [];
        foreach ($labels as $status => [$label, $tone]) {
            $out[] = ['label' => $label, 'count' => $counts[$status] ?? 0, 'tone' => $tone];
        }

        return $out;
    }

    /**
     * Total de envíos recibidos y entregados en la ventana (para el valor grande del gráfico).
     *
     * @return array{received: int, delivered: int}
     */
    public function totals(): array
    {
        return [
            'received' => array_sum($this->receivedPerDay()['values']),
            'delivered' => array_sum($this->deliveredPerDay()['values']),
        ];
    }

    /**
     * Arma una serie diaria continua de 14 días a partir de un resolver que devuelve [fecha 'Y-m-d' => conteo].
     *
     * @param  \Closure(): array<string, int>                 $resolver
     * @return array{labels: list<string>, values: list<int>}
     */
    private function dailySeries(\Closure $resolver): array
    {
        $byDay = [];
        try {
            $byDay = $resolver();
        } catch (Throwable) {
            $byDay = [];
        }

        $labels = [];
        $values = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i);
            $labels[] = $day->isoFormat('DD');
            $values[] = $byDay[$day->toDateString()] ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }
}
