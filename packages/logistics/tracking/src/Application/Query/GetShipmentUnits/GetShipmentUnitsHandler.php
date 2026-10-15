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
 * Resuelve el detalle per-bulto de un envío. Enriquece cada bulto con su etiqueta humana ("N/M")
 * leída EN LOTE desde shipment_units (lado lectura, sin N+1 ni dependencia composer entre contextos).
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

        $labels = $this->resolveLabels(array_map(static fn ($unit): string => $unit->unitId(), $units));

        return array_values(array_map(
            static fn ($unit): UnitTrackingView => UnitTrackingView::fromUnit($unit, $labels[$unit->unitId()] ?? null),
            $units,
        ));
    }

    /**
     * Carga en lote la etiqueta humana de cada bulto ("N/M", con pieza si viene fraccionado).
     *
     * @param  list<string>          $unitIds
     * @return array<string, string>
     */
    private function resolveLabels(array $unitIds): array
    {
        $labels = [];

        /** @var iterable<PhysicalUnitRecord> $records */
        $records = PhysicalUnitRecord::query()->whereIn('id', $unitIds)->get();

        foreach ($records as $record) {
            $label = $record->unit_index.'/'.$record->unit_total;
            if ($record->piece_total > 1) {
                $label .= ' ('.$record->piece_index.'/'.$record->piece_total.')';
            }
            $labels[$record->id] = $label;
        }

        return $labels;
    }
}
