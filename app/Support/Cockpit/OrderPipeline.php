<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lectura del "pipeline de pedidos" (OMS): agrupa las órdenes de comercio por estado del ciclo de vida en
 * columnas tipo tablero (pendiente → pagado → en preparación → cancelado), con el conteo por columna y las
 * tarjetas más relevantes de cada una. Hace visible el FLUJO comercial, no una lista plana — el gemelo del
 * pipeline de envíos para el frente de pedidos (DisTrack OS, Fase 3 "workflows visibles").
 *
 * SOLO lectura y tolerante (cada consulta en su try/catch; si falta una tabla/columna, degrada a 0/[]).
 * NUNCA expone un UUID como texto: la orden se identifica por el nombre del cliente (snapshot de `ship_to`)
 * y la tarjeta lleva el id solo para armar el enlace al hub del pedido.
 */
final class OrderPipeline
{
    /** Máximo de tarjetas por columna. */
    private const PER_COLUMN = 10;

    /**
     * Definición de columnas: estado → [etiqueta, tono]. El orden es el del flujo comercial.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const COLUMNS = [
        'pending' => ['Pendientes de pago', 'agent'],
        'paid' => ['Pagados', 'agent'],
        'fulfilling' => ['En preparación', 'warn'],
        'cancelled' => ['Cancelados', 'late'],
    ];

    /**
     * Columnas del pipeline con conteo y tarjetas.
     *
     * @return list<array{
     *   status: string,
     *   label: string,
     *   tone: string,
     *   count: int,
     *   cards: list<array{id: string, ref: string, total: string, meta: string}>
     * }>
     */
    public function columns(): array
    {
        $counts = $this->countsByStatus();
        $out = [];

        foreach (self::COLUMNS as $status => [$label, $tone]) {
            $out[] = [
                'status' => $status,
                'label' => $label,
                'tone' => $tone,
                'count' => $counts[$status] ?? 0,
                'cards' => $this->cards($status),
            ];
        }

        return $out;
    }

    /**
     * Conteo total de órdenes por estado.
     *
     * @return array<string, int>
     */
    private function countsByStatus(): array
    {
        try {
            /** @var array<string, int> $counts */
            $counts = DB::table('commerce_orders')
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(static fn ($c): int => (int) $c)
                ->all();

            return $counts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Tarjetas de una columna (las órdenes más recientes del estado), con cliente, total e ítems legibles.
     *
     * @return list<array{id: string, ref: string, total: string, meta: string}>
     */
    private function cards(string $status): array
    {
        try {
            $rows = DB::table('commerce_orders')
                ->where('status', $status)
                ->orderByDesc('created_at')
                ->limit(self::PER_COLUMN)
                ->get(['id', 'ship_to', 'lines', 'currency', 'created_at']);

            if ($rows->isEmpty()) {
                return [];
            }

            return array_values($rows->map(function ($r): array {
                $shipTo = $this->decode($r->ship_to);
                $lines = $this->decode($r->lines);
                $customer = is_string($shipTo['customer_name'] ?? null) && $shipTo['customer_name'] !== ''
                    ? $shipTo['customer_name']
                    : 'Cliente';

                return [
                    'id' => (string) $r->id,
                    'ref' => $customer,
                    'total' => $this->total($lines, is_string($r->currency) ? $r->currency : 'BOB'),
                    'meta' => $this->meta($lines, $r->created_at),
                ];
            })->all());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Total legible de la orden (suma de líneas: precio unitario × cantidad), ej. "795.00 BOB".
     *
     * @param array<array-key, mixed> $lines
     */
    private function total(array $lines, string $currency): string
    {
        $cents = 0;
        foreach ($lines as $line) {
            if (is_array($line)) {
                $cents += (int) ($line['unit_price_cents'] ?? 0) * (int) ($line['quantity'] ?? 0);
            }
        }

        return number_format($cents / 100, 2).' '.$currency;
    }

    /**
     * Resumen "N ítems · 05 nov" (ítems de la orden + fecha legible), tolerante.
     *
     * @param array<array-key, mixed> $lines
     */
    private function meta(array $lines, mixed $createdAt): string
    {
        $items = 0;
        foreach ($lines as $line) {
            if (is_array($line)) {
                $items += (int) ($line['quantity'] ?? 0);
            }
        }
        $itemsLabel = $items === 1 ? '1 ítem' : $items.' ítems';

        $day = '';
        if (is_string($createdAt) && $createdAt !== '') {
            try {
                $day = ' · '.Carbon::parse($createdAt)->isoFormat('DD MMM');
            } catch (Throwable) {
                $day = '';
            }
        }

        return $itemsLabel.$day;
    }

    /**
     * Decodifica una columna jsonb que puede llegar como string o como array.
     *
     * @return array<array-key, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : [];
            } catch (Throwable) {
                return [];
            }
        }

        return [];
    }
}
