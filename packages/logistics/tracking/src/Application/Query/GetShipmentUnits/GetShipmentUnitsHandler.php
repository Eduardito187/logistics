<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentUnits;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Tracking\Application\ReadModel\UnitTrackingView;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;

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

        return array_values(array_map(
            static fn ($unit): UnitTrackingView => UnitTrackingView::fromUnit(
                $unit,
                $resolved[$unit->unitId()]['label'] ?? null,
                $resolved[$unit->unitId()]['product'] ?? [],
            ),
            $units,
        ));
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
     * nunca es null (cae a ""), y si falta el nombre se usa el SKU y, si falta todo, "Producto". La foto
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
