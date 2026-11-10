<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Home de la TIENDA (Fase 4 — homes por rol): "mi tienda". Para la cuenta de tienda logueada (vinculada
 * por users.node_id a un nodo de la red), resume los envíos destinados a su tienda por estado y lista los
 * más recientes. SOLO lectura y tolerante (cada consulta en su try/catch). Sin UUID visible: la tienda se
 * identifica por su nombre/código y los envíos por su nº de pedido y cliente.
 */
final class StoreHome
{
    /** Máximo de envíos recientes a mostrar. */
    private const RECENT = 8;

    /** Estados terminales de un envío. */
    private const TERMINAL = ['DELIVERED', 'FAILED', 'CANCELLED'];

    /**
     * El resumen de la tienda, en primitivos y tolerante.
     *
     * @return array{
     *   node_name: string|null,
     *   node_code: string|null,
     *   counts: array{total: int, active: int, received: int, planned: int, in_execution: int, delivered: int},
     *   recent: list<array{id: string, ref: string, customer: string, status: string, window: string|null}>
     * }
     */
    public function overview(string $nodeId): array
    {
        $node = $this->node($nodeId);

        return [
            'node_name' => $node['name'],
            'node_code' => $node['code'],
            'counts' => $this->counts($nodeId),
            'recent' => $this->recent($nodeId),
        ];
    }

    /**
     * Nombre y código del nodo de la tienda.
     *
     * @return array{name: string|null, code: string|null}
     */
    private function node(string $nodeId): array
    {
        try {
            $row = DB::table('network_nodes')->where('id', $nodeId)->first(['name', 'code']);
            if ($row === null) {
                return ['name' => null, 'code' => null];
            }

            return [
                'name' => is_string($row->name) ? $row->name : null,
                'code' => is_string($row->code) ? $row->code : null,
            ];
        } catch (Throwable) {
            return ['name' => null, 'code' => null];
        }
    }

    /**
     * Conteo de envíos destinados a la tienda por estado.
     *
     * @return array{total: int, active: int, received: int, planned: int, in_execution: int, delivered: int}
     */
    private function counts(string $nodeId): array
    {
        $zero = ['total' => 0, 'active' => 0, 'received' => 0, 'planned' => 0, 'in_execution' => 0, 'delivered' => 0];

        try {
            /** @var array<string, int> $byStatus */
            $byStatus = DB::table('shipments')
                ->where('destination_node_id', $nodeId)
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(static fn ($c): int => (int) $c)
                ->all();

            $total = array_sum($byStatus);
            $active = 0;
            foreach ($byStatus as $status => $count) {
                if (!in_array($status, self::TERMINAL, true)) {
                    $active += $count;
                }
            }

            return [
                'total' => $total,
                'active' => $active,
                'received' => $byStatus['RECEIVED'] ?? 0,
                'planned' => $byStatus['PLANNED'] ?? 0,
                'in_execution' => $byStatus['IN_EXECUTION'] ?? 0,
                'delivered' => $byStatus['DELIVERED'] ?? 0,
            ];
        } catch (Throwable) {
            return $zero;
        }
    }

    /**
     * Envíos recientes destinados a la tienda.
     *
     * @return list<array{id: string, ref: string, customer: string, status: string, window: string|null}>
     */
    private function recent(string $nodeId): array
    {
        try {
            $rows = DB::table('shipments')
                ->where('destination_node_id', $nodeId)
                ->orderByRaw('coalesce(delivery_date, created_at::date) desc')
                ->orderByDesc('created_at')
                ->limit(self::RECENT)
                ->get(['id', 'external_id', 'customer_name', 'status', 'delivery_date', 'delivery_window_start', 'delivery_window_end']);

            return array_values($rows->map(function ($r): array {
                return [
                    'id' => (string) $r->id,
                    'ref' => (string) ($r->external_id ?: $r->customer_name ?: 'Envío'),
                    'customer' => (string) ($r->customer_name ?: '—'),
                    'status' => (string) $r->status,
                    'window' => $this->window($r->delivery_date, $r->delivery_window_start, $r->delivery_window_end),
                ];
            })->all());
        } catch (Throwable) {
            return [];
        }
    }

    /** Franja comprometida legible ("05 nov 11:00–16:00"), o null. */
    private function window(mixed $date, mixed $start, mixed $end): ?string
    {
        if (!is_string($start) || $start === '' || !is_string($end) || $end === '') {
            return null;
        }

        $day = '';
        if (is_string($date) && $date !== '') {
            try {
                $day = Carbon::parse($date)->isoFormat('DD MMM').' ';
            } catch (Throwable) {
                $day = '';
            }
        }

        return trim($day.$start.'–'.$end);
    }
}
