<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetPublicUnits;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\PhysicalUnitRecord;
use Logistics\Tracking\Application\ReadModel\PublicUnitView;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;

/**
 * Resuelve el detalle público de productos a partir del token de la URL: token → envío → sus bultos.
 *
 * Un token con forma inválida o inexistente devuelve null (igual que la vista pública del envío): no se
 * revela si el token existe. Las etiquetas van en español (copy de cliente) y se leen en lote desde
 * shipment_units, sin PII ni claves internas.
 *
 * @implements QueryHandler<GetPublicUnits, list<PublicUnitView>|null>
 */
final class GetPublicUnitsHandler implements QueryHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
        private readonly UnitTrackingRepository $units,
    ) {}

    /**
     * @return list<PublicUnitView>|null
     */
    public function handle(Query $query): ?array
    {
        if (!$query instanceof GetPublicUnits) {
            throw TrackingException::because('GetPublicUnitsHandler solo maneja GetPublicUnits');
        }

        try {
            $token = TrackingToken::fromString($query->token);
        } catch (TrackingException) {
            return null;
        }

        $tracking = $this->trackings->findByToken($token);
        if ($tracking === null) {
            return null;
        }

        $units = $this->units->listByShipmentId($tracking->shipmentId());
        if ($units === []) {
            return [];
        }

        $labels = $this->resolveLabels(array_map(static fn ($unit): string => $unit->unitId(), $units));

        return array_values(array_map(
            static fn ($unit): PublicUnitView => PublicUnitView::fromUnit(
                $unit,
                $labels[$unit->unitId()] ?? 'Producto',
            ),
            $units,
        ));
    }

    /**
     * Etiqueta humana en español de cada unidad física ("Unidad N de M", con pieza si viene
     * fraccionada), leída en lote desde shipment_units.
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
            $label = 'Unidad '.$record->unit_index.' de '.$record->unit_total;
            if ($record->piece_total > 1) {
                $label .= ' (pieza '.$record->piece_index.' de '.$record->piece_total.')';
            }
            $labels[$record->id] = $label;
        }

        return $labels;
    }
}
