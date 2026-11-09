<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA de la página de Analítica de operaciones (Fase 4 — "datos que se ven"): deriva
 * métricas agregadas REALES de las tablas existentes, sin tabla de histórico y sin mock. Complementa a
 * {@see CockpitPulse} (mosaicos) y {@see CockpitTrends} (series diarias) con cortes que el cockpit no
 * tenía: efectividad de entrega y concentración de demanda por nodo destino.
 *
 * SOLO lectura y tolerante (cada corte en su try/catch; si la tabla falta, degrada a vacío/cero). Resuelve
 * el nodo a su nombre legible: NUNCA expone un UUID. Cacheo corto heredado del cockpit.
 */
final class AnalyticsBoard
{
    use ReadsCockpitAggregates;

    /** Máximo de nodos en el ranking de destinos. */
    private const TOP_NODES = 8;

    /**
     * Efectividad de entrega sobre las entregas CERRADAS (entregadas + fallidas): cuántas llegaron bien.
     *
     * @return array{delivered: int, failed: int, closed: int, pct: int|null}
     */
    public function effectiveness(): array
    {
        return $this->remember('analytics:effectiveness', function (): array {
            try {
                /** @var array<string, int> $byStatus */
                $byStatus = DB::table('execution_deliveries')
                    ->selectRaw('status, count(*) as c')
                    ->groupBy('status')
                    ->pluck('c', 'status')
                    ->map(static fn ($v): int => (int) $v)
                    ->all();

                $delivered = $byStatus['DELIVERED'] ?? 0;
                $failed = $byStatus['FAILED'] ?? 0;
                $closed = $delivered + $failed;

                return [
                    'delivered' => $delivered,
                    'failed' => $failed,
                    'closed' => $closed,
                    'pct' => $closed > 0 ? (int) round($delivered / $closed * 100) : null,
                ];
            } catch (Throwable) {
                return ['delivered' => 0, 'failed' => 0, 'closed' => 0, 'pct' => null];
            }
        });
    }

    /**
     * Concentración de la demanda: los nodos destino con más envíos, resueltos a nombre legible. Para un
     * gráfico de barras (etiqueta = nombre del nodo, nunca el id).
     *
     * @return list<array{label: string, count: int}>
     */
    public function topDestinations(): array
    {
        return $this->remember('analytics:top-destinations', function (): array {
            try {
                $rows = DB::table('shipments')
                    ->whereNotNull('destination_node_id')
                    ->selectRaw('destination_node_id, count(*) as c')
                    ->groupBy('destination_node_id')
                    ->orderByDesc('c')
                    ->limit(self::TOP_NODES)
                    ->get();

                $ids = array_values($rows->pluck('destination_node_id')->map(static fn ($v): string => (string) $v)->all());
                $names = $this->nodeNames($ids);

                $out = [];
                foreach ($rows as $row) {
                    $id = (string) $row->destination_node_id;
                    $out[] = [
                        'label' => $names[$id] ?? 'Nodo',
                        'count' => (int) $row->c,
                    ];
                }

                return $out;
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * Nombres legibles de nodos por id, en lote (para no exponer UUIDs).
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function nodeNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            /** @var array<string, string> $names */
            $names = DB::table('network_nodes')
                ->whereIn('id', $ids)
                ->pluck('name', 'id')
                ->all();

            return $names;
        } catch (Throwable) {
            return [];
        }
    }
}
