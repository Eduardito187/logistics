<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve una ETIQUETA LEGIBLE de un bulto a partir de su identificador (`unit_id`) para la capa
 * Filament de Execution.
 *
 * Los escaneos y las recepciones guardan `unit_id`, que según el origen puede ser el UUID del bulto
 * (tabla `shipment_units`, p. ej. los escaneos registrados desde el panel) o un código legible de la
 * etiqueta escaneada en terreno (p. ej. "U-10001"). Aquí se enriquece a una etiqueta humana (referencia
 * del pedido + índice de bulto/pieza) leyendo `shipment_units` + `shipments` en SOLO lectura mediante el
 * query builder, sin acoplar el Eloquent del contexto Shipments. El mapa id→etiqueta se memoiza por
 * request (evita el N+1 al pintar tablas e infolists). NUNCA se devuelve un UUID crudo: un UUID que no
 * resuelve a un bulto conocido se oculta como '—'.
 */
final class UnitNames
{
    /** @var array<string, string> Memoización por request de los identificadores ya resueltos. */
    private static array $resolved = [];

    /**
     * Etiqueta legible de un bulto por su `unit_id`:
     *  - UUID que existe en `shipment_units` → "pedido · unit i/n pc i/n".
     *  - UUID que no resuelve a un bulto conocido → '—' (nunca se expone el id crudo).
     *  - código legible de etiqueta (no UUID) → se muestra tal cual (es justo lo que ve el operador).
     *  - vacío/null → '—'.
     */
    public static function label(?string $unitId): string
    {
        if ($unitId === null || $unitId === '') {
            return '—';
        }

        if (array_key_exists($unitId, self::$resolved)) {
            return self::$resolved[$unitId];
        }

        return self::$resolved[$unitId] = self::resolve($unitId);
    }

    /**
     * Resuelve un `unit_id` a su etiqueta legible. Solo un UUID puede ser id de `shipment_units`; un
     * código escaneado legible se devuelve sin tocar.
     */
    private static function resolve(string $unitId): string
    {
        if (!self::looksLikeUuid($unitId)) {
            return $unitId;
        }

        /** @var object{external_id: string|null, unit_index: int|string, unit_total: int|string, piece_index: int|string, piece_total: int|string}|null $row */
        $row = DB::table('shipment_units')
            ->leftJoin('shipments', 'shipments.id', '=', 'shipment_units.shipment_id')
            ->where('shipment_units.id', $unitId)
            ->first([
                'shipments.external_id',
                'shipment_units.unit_index',
                'shipment_units.unit_total',
                'shipment_units.piece_index',
                'shipment_units.piece_total',
            ]);

        if ($row === null) {
            // UUID no resuelto: nunca mostrar el identificador crudo.
            return '—';
        }

        $order = ($row->external_id !== null && $row->external_id !== '') ? $row->external_id : '—';

        return "{$order} · unit {$row->unit_index}/{$row->unit_total} pc {$row->piece_index}/{$row->piece_total}";
    }

    /**
     * Heurística mínima para detectar un UUID (cualquier versión) y así nunca mostrarlo crudo.
     */
    private static function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
