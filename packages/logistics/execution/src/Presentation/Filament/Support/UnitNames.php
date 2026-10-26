<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Support;

use Illuminate\Support\Facades\DB;

/**
 * Resuelve una ETIQUETA LEGIBLE de un producto a partir del identificador de su unidad física
 * (`unit_id`) para la capa Filament de Execution.
 *
 * Los escaneos y las recepciones guardan `unit_id`, que según el origen puede ser el UUID de la unidad
 * física (tabla `shipment_units`, p. ej. los escaneos registrados desde el panel) o un código legible de
 * la etiqueta escaneada en terreno (p. ej. "U-10001"). Aquí se enriquece a una etiqueta humana (nombre
 * del producto + SKU, más la pieza física N de M si la unidad está partida) uniendo `shipment_units` con
 * `shipment_lines` en SOLO lectura mediante el query builder, sin acoplar el Eloquent del contexto
 * Shipments. El mapa id→etiqueta se memoiza por request (evita el N+1 al pintar tablas e infolists).
 * NUNCA se devuelve un UUID crudo ni vacío: un UUID que no resuelve a un producto conocido cae al texto
 * "Producto".
 */
final class UnitNames
{
    /** @var array<string, string> Memoización por request de los identificadores ya resueltos. */
    private static array $resolved = [];

    /**
     * Etiqueta legible de un producto por el `unit_id` de su unidad física:
     *  - UUID que existe en `shipment_units` → "{nombre} · {SKU}" (+ "(N de M)" si hay pieza física).
     *  - UUID que no resuelve a un producto conocido → "Producto" (nunca se expone el id crudo).
     *  - código legible de etiqueta (no UUID) → se muestra tal cual (es justo lo que ve el operador).
     *  - vacío/null → "Producto".
     */
    public static function label(?string $unitId): string
    {
        if ($unitId === null || $unitId === '') {
            return 'Producto';
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

        /** @var object{product_name: string|null, sku: string|null, unit_index: int|string|null, unit_total: int|string|null, piece_index: int|string|null, piece_total: int|string|null}|null $row */
        $row = DB::table('shipment_units')
            ->leftJoin('shipment_lines', 'shipment_lines.id', '=', 'shipment_units.line_id')
            ->where('shipment_units.id', $unitId)
            ->first([
                'shipment_lines.product_name',
                'shipment_lines.sku',
                'shipment_units.unit_index',
                'shipment_units.unit_total',
                'shipment_units.piece_index',
                'shipment_units.piece_total',
            ]);

        if ($row === null) {
            // UUID no resuelto: nunca mostrar el identificador crudo ni vacío.
            return 'Producto';
        }

        $name = is_string($row->product_name) ? trim($row->product_name) : '';
        $sku = is_string($row->sku) ? trim($row->sku) : '';

        // Fallback: nombre + SKU; si falta el nombre, el SKU; si faltan ambos, "Producto".
        if ($name !== '' && $sku !== '') {
            $base = "{$name} · {$sku}";
        } elseif ($name !== '') {
            $base = $name;
        } elseif ($sku !== '') {
            $base = $sku;
        } else {
            $base = 'Producto';
        }

        $piece = self::pieceSuffix($row);

        return $piece === '' ? $base : "{$base} {$piece}";
    }

    /**
     * Sufijo de la pieza física "(N de M)": la pieza fraccionada si la unidad está partida, o la unidad
     * N de M del pedido en caso contrario. Vacío si no hay nada que precisar.
     *
     * @param  object{piece_index: int|string|null, piece_total: int|string|null, unit_index: int|string|null, unit_total: int|string|null}  $row
     */
    private static function pieceSuffix(object $row): string
    {
        $pieceIndex = is_numeric($row->piece_index) ? (int) $row->piece_index : null;
        $pieceTotal = is_numeric($row->piece_total) ? (int) $row->piece_total : null;
        if ($pieceIndex !== null && $pieceTotal !== null && $pieceTotal > 1) {
            return "({$pieceIndex} de {$pieceTotal})";
        }

        $unitIndex = is_numeric($row->unit_index) ? (int) $row->unit_index : null;
        $unitTotal = is_numeric($row->unit_total) ? (int) $row->unit_total : null;
        if ($unitIndex !== null && $unitTotal !== null && $unitTotal > 1) {
            return "({$unitIndex} de {$unitTotal})";
        }

        return '';
    }

    /**
     * Heurística mínima para detectar un UUID (cualquier versión) y así nunca mostrarlo crudo.
     */
    private static function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
