<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\ReadModel;

use Illuminate\Support\Facades\DB;
use Logistics\Labels\Application\Contract\LabelUnitResolver;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Throwable;

/**
 * Resuelve una etiqueta (ULID del QR) al BULTO FÍSICO (shipment_units) y su envío, por correlación
 * determinista — SOLO lectura, cross-context, tolerante:
 *
 *   etiqueta (labels_emitted.signed_json → orderNumber, lineNumber, unit.index, piece.index)
 *     → envío  (shipments.external_id = orderNumber)
 *     → línea  (la N-ésima shipment_lines del envío, N = lineNumber)
 *     → bulto  (shipment_units con ese shipment_id + line_id + unit_index + piece_index)
 *
 * Si algo falta (etiqueta inexistente, sin envío, sin línea o sin bulto), devuelve `resolved=false` con lo
 * que se haya podido resolver. NO cambia el formato del QR (el id sigue siendo el ULID): el puente vive
 * acá, en lectura.
 */
final class EloquentLabelUnitResolver implements LabelUnitResolver
{
    public function resolve(string $labelId): LabelUnitView
    {
        $unresolved = new LabelUnitView($labelId, false, null, null, null, null, null, null, null, null, null);

        // 1) Etiqueta emitida + su payload firmado.
        try {
            $row = DB::table('labels_emitted')->where('label_id', $labelId)->first(['signed_json']);
        } catch (Throwable) {
            return $unresolved;
        }
        if ($row === null || !is_string($row->signed_json) || $row->signed_json === '') {
            return $unresolved;
        }

        try {
            $payload = SignedLabel::fromQrContent($row->signed_json)->payload;
        } catch (Throwable) {
            return $unresolved;
        }

        $orderNumber = $payload->orderNumber;
        $lineNumber = $payload->lineNumber;
        $sku = $payload->sku;
        $unitIndex = $payload->unit->index;
        $unitTotal = $payload->unit->total;
        $pieceIndex = $payload->piece->index;
        $pieceTotal = $payload->piece->total;

        $base = static fn (?string $unitId, ?string $shipmentId, ?string $productName): LabelUnitView => new LabelUnitView(
            labelId: $labelId,
            resolved: $unitId !== null,
            unitId: $unitId,
            shipmentId: $shipmentId,
            sku: $sku,
            productName: $productName,
            lineNumber: $lineNumber,
            unitIndex: $unitIndex,
            unitTotal: $unitTotal,
            pieceIndex: $pieceIndex,
            pieceTotal: $pieceTotal,
        );

        // 2) Envío de la orden.
        try {
            $shipment = DB::table('shipments')
                ->where('external_id', $orderNumber)
                ->orderByDesc('created_at')
                ->first(['id']);
        } catch (Throwable) {
            return $base(null, null, null);
        }
        if ($shipment === null) {
            return $base(null, null, null);
        }
        $shipmentId = (string) $shipment->id;

        // 3) La N-ésima línea del envío (lineNumber es 1-based, en el orden de ingreso), SOLO si su sku
        //    coincide con el de la etiqueta (guard contra desalineación emisión↔ingreso).
        try {
            $line = DB::table('shipment_lines')
                ->where('shipment_id', $shipmentId)
                ->orderBy('id')
                ->offset(max(0, $lineNumber - 1))
                ->limit(1)
                ->first(['id', 'sku', 'product_name']);
        } catch (Throwable) {
            return $base(null, $shipmentId, null);
        }
        if ($line === null) {
            return $base(null, $shipmentId, null);
        }
        $productName = is_string($line->product_name) && $line->product_name !== '' ? $line->product_name : null;
        if (!is_string($line->sku) || $line->sku !== $sku) {
            // La línea en esa posición no es del sku de la etiqueta: no se puede atar el bulto con seguridad.
            return $base(null, $shipmentId, $productName);
        }

        // 4) El bulto físico con ese índice de unidad y de pieza.
        try {
            $unit = DB::table('shipment_units')
                ->where('shipment_id', $shipmentId)
                ->where('line_id', $line->id)
                ->where('unit_index', $unitIndex)
                ->where('piece_index', $pieceIndex)
                ->first(['id']);
        } catch (Throwable) {
            return $base(null, $shipmentId, $productName);
        }

        $unitId = $unit !== null ? (string) $unit->id : null;

        return $base($unitId, $shipmentId, $productName);
    }
}
