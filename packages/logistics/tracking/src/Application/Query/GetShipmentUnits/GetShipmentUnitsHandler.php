<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentUnits;

use Illuminate\Support\Facades\DB;
use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Tracking\Application\ReadModel\UnitTrackingView;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Throwable;

/**
 * Resuelve el detalle por unidad física de un envío. Enriquece cada unidad con su etiqueta humana
 * ("N/M") y el producto que contiene (nombre + SKU), leídos EN LOTE desde shipment_units → shipment_lines
 * (lado lectura, sin N+1 ni dependencia composer entre contextos).
 *
 * @implements QueryHandler<GetShipmentUnits, list<UnitTrackingView>>
 */
final class GetShipmentUnitsHandler implements QueryHandler
{
    public function __construct(
        private readonly UnitTrackingRepository $units,
    ) {}

    /**
     * @return list<UnitTrackingView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof GetShipmentUnits) {
            throw TrackingException::because('GetShipmentUnitsHandler solo maneja GetShipmentUnits');
        }

        $units = $this->units->listByShipmentId($query->shipmentId);
        if ($units === []) {
            return [];
        }

        $resolved = $this->resolveUnits(array_map(static fn ($unit): string => $unit->unitId(), $units));
        $labelIds = $this->resolveLabelIds($query->shipmentId);

        return array_values(array_map(
            static fn ($unit): UnitTrackingView => UnitTrackingView::fromUnit(
                $unit,
                $resolved[$unit->unitId()]['label'] ?? null,
                $resolved[$unit->unitId()]['product'] ?? [],
                $labelIds[$unit->unitId()] ?? null,
            ),
            $units,
        ));
    }

    /**
     * Mapa unit_id → label_id (ULID del QR) de cada bulto del envío, por correlación inversa etiqueta↔bulto
     * (orden→envío, ordinal de línea + unit_index + piece_index). Prefiere la etiqueta vigente sobre las
     * revocadas. Tolerante: si falta el envío/etiquetas, devuelve []. Para que el chofer mapee offline.
     *
     * @return array<string, string>
     */
    private function resolveLabelIds(string $shipmentId): array
    {
        try {
            // Las etiquetas se guardan por número de ORDEN (labels_emitted.order_id). En multishipping el
            // external_id del envío hermano es compuesto, así que se usa order_reference (cae a external_id).
            $row = DB::table('shipments')->where('id', $shipmentId)->first(['external_id', 'order_reference']);
            $order = is_object($row) && isset($row->order_reference) && is_string($row->order_reference) && $row->order_reference !== ''
                ? $row->order_reference
                : (is_object($row) && is_string($row->external_id) ? $row->external_id : null);
            if (!is_string($order) || $order === '') {
                return [];
            }

            // Ordinal 1-based de cada línea del envío (el lineNumber de la etiqueta es esa posición).
            $ordinalByLineId = [];
            $n = 0;
            foreach (DB::table('shipment_lines')->where('shipment_id', $shipmentId)->orderBy('id')->pluck('id') as $lineId) {
                $n++;
                $ordinalByLineId[(string) $lineId] = $n;
            }

            // Mapa (ordinal|unit_index|piece_index) → label_id, prefiriendo la vigente.
            $labelByKey = [];
            foreach (DB::table('labels_emitted')->where('order_id', $order)->get(['label_id', 'signed_json', 'revoked_at']) as $row) {
                if (!is_string($row->signed_json) || $row->signed_json === '') {
                    continue;
                }
                try {
                    $p = SignedLabel::fromQrContent($row->signed_json)->payload;
                } catch (Throwable) {
                    continue;
                }
                $key = $p->lineNumber.'|'.$p->unit->index.'|'.$p->piece->index;
                $revoked = $row->revoked_at !== null;
                $existing = $labelByKey[$key] ?? null;
                if ($existing === null || ($existing['revoked'] && !$revoked)) {
                    $labelByKey[$key] = ['label_id' => (string) $row->label_id, 'revoked' => $revoked];
                }
            }

            // Bultos del envío → su label_id por la clave (ordinal, unit_index, piece_index).
            $out = [];
            foreach (DB::table('shipment_units')->where('shipment_id', $shipmentId)->get(['id', 'line_id', 'unit_index', 'piece_index']) as $u) {
                $ordinal = $ordinalByLineId[(string) $u->line_id] ?? null;
                if ($ordinal === null) {
                    continue;
                }
                $key = $ordinal.'|'.((int) $u->unit_index).'|'.((int) $u->piece_index);
                if (isset($labelByKey[$key])) {
                    $out[(string) $u->id] = $labelByKey[$key]['label_id'];
                }
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Carga en lote (una consulta, shipment_units LEFT JOIN shipment_lines) la etiqueta humana de cada
     * unidad física ("N/M", con pieza si viene fraccionada) y el producto que contiene (SKU, nombre, foto, pieza).
     *
     * @param  list<string>                                                                                                                                                         $unitIds
     * @return array<string, array{label: string, product: array{sku: string, product_name: string, product_image_url: string|null, piece_index: int|null, piece_total: int|null}}>
     */
    private function resolveUnits(array $unitIds): array
    {
        $resolved = [];

        $records = PhysicalUnitRecord::query()
            ->leftJoin('shipment_lines', 'shipment_lines.id', '=', 'shipment_units.line_id')
            ->whereIn('shipment_units.id', $unitIds)
            ->toBase()
            ->get([
                'shipment_units.id',
                'shipment_units.unit_index',
                'shipment_units.unit_total',
                'shipment_units.piece_index',
                'shipment_units.piece_total',
                'shipment_lines.sku',
                'shipment_lines.product_name',
                'shipment_lines.product_image_url',
            ]);

        foreach ($records as $record) {
            $pieceIndex = is_numeric($record->piece_index) ? (int) $record->piece_index : null;
            $pieceTotal = is_numeric($record->piece_total) ? (int) $record->piece_total : null;

            $resolved[(string) $record->id] = [
                'label' => $this->unitLabel($record, $pieceIndex, $pieceTotal),
                'product' => $this->unitProduct($record, $pieceIndex, $pieceTotal),
            ];
        }

        return $resolved;
    }

    /**
     * Etiqueta humana "N/M" de la unidad física, con la pieza fraccionada entre paréntesis si está partida.
     */
    private function unitLabel(\stdClass $record, ?int $pieceIndex, ?int $pieceTotal): string
    {
        $label = $record->unit_index.'/'.$record->unit_total;
        if ($pieceTotal !== null && $pieceTotal > 1) {
            $label .= ' ('.$pieceIndex.'/'.$pieceTotal.')';
        }

        return $label;
    }

    /**
     * Producto que contiene la unidad física. Nombre y SKU SIEMPRE legibles (rule del dueño): el SKU
     * nunca es null (cae a ""), y si falta el nombre se usa el SKU y, si faltan ambos, "Producto". La foto
     * sí puede faltar (null).
     *
     * @return array{sku: string, product_name: string, product_image_url: string|null, piece_index: int|null, piece_total: int|null}
     */
    private function unitProduct(\stdClass $record, ?int $pieceIndex, ?int $pieceTotal): array
    {
        $sku = is_string($record->sku) ? trim($record->sku) : '';
        $name = is_string($record->product_name) ? trim($record->product_name) : '';
        if ($name === '') {
            $name = $sku !== '' ? $sku : 'Producto';
        }

        return [
            'sku' => $sku,
            'product_name' => $name,
            'product_image_url' => is_string($record->product_image_url) && $record->product_image_url !== '' ? $record->product_image_url : null,
            'piece_index' => $pieceIndex,
            'piece_total' => $pieceTotal,
        ];
    }
}
