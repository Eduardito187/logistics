<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;

/**
 * Compositor de LECTURA de los reportes de operaciones (Fase 5 — reportes y exportación): arma, a partir
 * de los read-services del cockpit ({@see CockpitTrends}, {@see AnalyticsBoard}, {@see CockpitPulse}), los
 * DATASETS listos para exportar a CSV y el RESUMEN diario del digest por correo. No consulta la base por
 * su cuenta: reutiliza las derivaciones ya existentes (una sola fuente de verdad). SOLO lectura y
 * tolerante. NUNCA expone UUIDs (los nodos ya vienen resueltos a nombre).
 */
final class ReportBuilder
{
    /** Ventana (días) de las series diarias, alineada con CockpitTrends. */
    private const int WINDOW_DAYS = 14;

    public function __construct(
        private readonly CockpitTrends $trends,
        private readonly AnalyticsBoard $analytics,
    ) {}

    /**
     * Datasets CSV disponibles, con su etiqueta legible (para los botones de la página).
     *
     * @return array<string, string>
     */
    public function datasets(): array
    {
        return [
            'deliveries' => 'Entregas por día',
            'received' => 'Recibidos por día',
            'status' => 'Distribución por estado',
            'effectiveness' => 'Efectividad de entrega',
            'destinations' => 'Concentración por nodo destino',
        ];
    }

    /**
     * Estructura de un CSV: nombre de archivo, cabeceras (español) y filas. Dataset desconocido → vacío.
     *
     * @return array{filename: string, headers: list<string>, rows: list<list<string|int>>}
     */
    public function csv(string $dataset): array
    {
        $stamp = Carbon::now()->format('Ymd');

        return match ($dataset) {
            'deliveries' => [
                'filename' => "entregas-por-dia-{$stamp}.csv",
                'headers' => ['Fecha', 'Entregas'],
                'rows' => $this->datedRows($this->trends->deliveredPerDay()['values']),
            ],
            'received' => [
                'filename' => "recibidos-por-dia-{$stamp}.csv",
                'headers' => ['Fecha', 'Envíos recibidos'],
                'rows' => $this->datedRows($this->trends->receivedPerDay()['values']),
            ],
            'status' => [
                'filename' => "distribucion-por-estado-{$stamp}.csv",
                'headers' => ['Estado', 'Envíos'],
                'rows' => array_map(
                    static fn (array $r): array => [(string) $r['label'], (int) $r['count']],
                    $this->trends->statusDistribution(),
                ),
            ],
            'effectiveness' => [
                'filename' => "efectividad-entrega-{$stamp}.csv",
                'headers' => ['Métrica', 'Valor'],
                'rows' => $this->effectivenessRows(),
            ],
            'destinations' => [
                'filename' => "concentracion-por-nodo-{$stamp}.csv",
                'headers' => ['Nodo destino', 'Envíos'],
                'rows' => array_map(
                    static fn (array $r): array => [(string) $r['label'], (int) $r['count']],
                    $this->analytics->topDestinations(),
                ),
            ],
            default => ['filename' => 'reporte.csv', 'headers' => [], 'rows' => []],
        };
    }

    /**
     * Resumen diario para el digest por correo: totales de la ventana, efectividad y excepciones.
     *
     * @return array{window_days: int, received: int, delivered: int, effectiveness_pct: int|null, failed: int, status: list<array{label: string, count: int, tone: string}>, top_destination: ?array{label: string, count: int}}
     */
    public function dailySummary(): array
    {
        $totals = $this->trends->totals();
        $eff = $this->analytics->effectiveness();
        $top = $this->analytics->topDestinations();

        return [
            'window_days' => self::WINDOW_DAYS,
            'received' => $totals['received'],
            'delivered' => $totals['delivered'],
            'effectiveness_pct' => $eff['pct'],
            'failed' => $eff['failed'],
            'status' => $this->trends->statusDistribution(),
            'top_destination' => $top[0] ?? null,
        ];
    }

    /**
     * Empareja una serie de valores (ordenada de hace 13 días a hoy) con su fecha real 'Y-m-d'.
     *
     * @param  list<int>              $values
     * @return list<list<string|int>>
     */
    private function datedRows(array $values): array
    {
        $rows = [];
        $count = count($values);
        foreach ($values as $i => $value) {
            $date = Carbon::today()->subDays($count - 1 - $i)->toDateString();
            $rows[] = [$date, (int) $value];
        }

        return $rows;
    }

    /**
     * Filas del CSV de efectividad.
     *
     * @return list<list<string|int>>
     */
    private function effectivenessRows(): array
    {
        $eff = $this->analytics->effectiveness();

        return [
            ['Entregadas', $eff['delivered']],
            ['Fallidas', $eff['failed']],
            ['Cerradas', $eff['closed']],
            ['Efectividad (%)', $eff['pct'] ?? 0],
        ];
    }
}
