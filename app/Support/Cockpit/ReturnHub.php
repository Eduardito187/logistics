<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de una devolución (RMA): agrega su ficha (referencia original, tipo,
 * estado del ciclo, disposición, modo, almacén destino) y sus líneas (productos devueltos con motivo).
 * Datos reales y tolerantes (cada fuente en su try/catch). SOLO lectura; sin UUID visible (la referencia es
 * `original_reference`, el almacén se resuelve a nombre).
 */
final class ReturnHub
{
    /**
     * Todo lo que la vista del hub de la devolución necesita, en primitivos y tolerante.
     *
     * @return array{
     *   ret: array{reference: string, status: string, kind: string, disposition: ?string, mode: ?string, warehouse: ?string, created: ?string}|null,
     *   lines: list<array{sku: string, quantity: int, reason: ?string}>,
     *   units: int,
     * }
     */
    public function overview(string $returnId): array
    {
        $ret = $this->ret($returnId);
        $lines = $this->lines($returnId);

        return [
            'ret' => $ret,
            'lines' => $lines,
            'units' => array_sum(array_map(static fn (array $l): int => $l['quantity'], $lines)),
        ];
    }

    /**
     * Ficha de la devolución, o null si no existe.
     *
     * @return array{reference: string, status: string, kind: string, disposition: ?string, mode: ?string, warehouse: ?string, created: ?string}|null
     */
    private function ret(string $returnId): ?array
    {
        try {
            $row = DB::table('returns')->where('id', $returnId)->first();
            if ($row === null) {
                return null;
            }

            return [
                'reference' => (string) ($row->original_reference ?? ''),
                'status' => (string) ($row->status ?? ''),
                'kind' => (string) ($row->kind ?? ''),
                'disposition' => $this->str($row->disposition ?? null),
                'mode' => $this->str($row->fulfillment_mode ?? null),
                'warehouse' => $this->warehouseName($this->str($row->warehouse_id ?? null)),
                'created' => $this->shortDate(is_string($row->created_at ?? null) ? $row->created_at : null),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Líneas (productos devueltos) de la devolución.
     *
     * @return list<array{sku: string, quantity: int, reason: ?string}>
     */
    private function lines(string $returnId): array
    {
        try {
            return array_values(DB::table('return_lines')
                ->where('return_id', $returnId)
                ->orderBy('sku')
                ->get(['sku', 'quantity', 'reason'])
                ->map(fn ($r): array => [
                    'sku' => (string) $r->sku,
                    'quantity' => (int) $r->quantity,
                    'reason' => $this->str($r->reason),
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /** Nombre del almacén (nodo) por id, o null. */
    private function warehouseName(?string $nodeId): ?string
    {
        if ($nodeId === null) {
            return null;
        }

        try {
            $name = DB::table('network_nodes')->where('id', $nodeId)->value('name');

            return is_string($name) && $name !== '' ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Normaliza a string no vacío o null. */
    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** Fecha legible corta, tolerante. */
    private function shortDate(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM YYYY HH:mm');
        } catch (Throwable) {
            return null;
        }
    }
}
