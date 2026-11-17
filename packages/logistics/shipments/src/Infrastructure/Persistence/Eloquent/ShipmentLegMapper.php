<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\Model\ShipmentLeg;
use Logistics\Shipments\Domain\ValueObject\HaulageMode;
use Logistics\Shipments\Domain\ValueObject\LegStatus;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;
use Throwable;

/**
 * Traduce entre el agregado {@see ShipmentItinerary} (y sus {@see ShipmentLeg}) y las filas Eloquent.
 * Toda la conversión a/desde primitivos vive acá, para que ni el dominio ni el repositorio conozcan la
 * forma de la tabla.
 */
final class ShipmentLegMapper
{
    /** Formato ISO-8601 con offset: PostgreSQL lo guarda en la zona correcta (evita el desfase del cast). */
    private const string TIMESTAMP_FORMAT = DateTimeImmutable::ATOM;

    /**
     * @return array<string, mixed>
     */
    public function toLegRow(ShipmentItinerary $itinerary, ShipmentLeg $leg): array
    {
        return [
            'id' => $leg->id()->toString(),
            'shipment_id' => $itinerary->shipmentId()->toString(),
            'seq' => $leg->seq(),
            'from_node_id' => $leg->fromNodeId(),
            'to_node_id' => $leg->toNodeId(),
            'from_department' => $leg->fromDepartment(),
            'to_department' => $leg->toDepartment(),
            'haulage_mode' => $leg->haulageMode()->value,
            'carrier_id' => $leg->carrierId(),
            'status' => $leg->status()->value,
            'departed_at' => $leg->departedAt()?->format(self::TIMESTAMP_FORMAT),
            'arrived_at' => $leg->arrivedAt()?->format(self::TIMESTAMP_FORMAT),
        ];
    }

    /**
     * Reconstruye el itinerario completo (todos sus tramos) desde las filas de un envío.
     *
     * @param iterable<ShipmentLegRecord> $records
     */
    public function toDomain(ShipmentId $shipmentId, iterable $records): ShipmentItinerary
    {
        $legs = [];
        foreach ($records as $record) {
            $legs[] = ShipmentLeg::create(
                ShipmentLegId::fromString($record->id),
                $record->seq,
                $record->from_node_id,
                $record->to_node_id,
                $record->from_department,
                $record->to_department,
                HaulageMode::fromLoose($record->haulage_mode),
                $record->carrier_id,
                LegStatus::fromLoose($record->status),
                self::timestampOrNull($record->departed_at),
                self::timestampOrNull($record->arrived_at),
            );
        }

        return ShipmentItinerary::reconstitute($shipmentId, $legs);
    }

    /**
     * Convierte un timestamp de la base a DateTimeImmutable. Tolerante: si falta o está sucio, devuelve
     * null en vez de romper la carga del itinerario.
     */
    private static function timestampOrNull(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
