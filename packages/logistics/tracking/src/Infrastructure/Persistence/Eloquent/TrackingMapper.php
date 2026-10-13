<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\Eta;
use Logistics\Tracking\Domain\ValueObject\GeoPoint;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\TruckPosition;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Traduce entre el agregado ShipmentTracking y su fila Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class TrackingMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(ShipmentTracking $tracking): array
    {
        $position = $tracking->lastPosition();

        return [
            'id' => $tracking->id()->toString(),
            'shipment_id' => $tracking->shipmentId(),
            'token' => $tracking->token()->toString(),
            'status' => $tracking->status()->value,
            'remaining_stops' => $tracking->remainingStops(),
            'eta' => $tracking->eta()?->toIso8601(),
            'last_lat' => $position !== null ? (string) $position->point->latitude : null,
            'last_lng' => $position !== null ? (string) $position->point->longitude : null,
            'last_position_at' => $position?->reportedAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Reconstruye el agregado desde su fila.
     */
    public function toDomain(TrackingRecord $record): ShipmentTracking
    {
        $eta = $record->eta !== null
            ? Eta::at(new DateTimeImmutable($record->eta))
            : null;

        $lastPosition = $record->last_lat !== null
            && $record->last_lng !== null
            && $record->last_position_at !== null
            ? TruckPosition::at(
                GeoPoint::of((float) $record->last_lat, (float) $record->last_lng),
                new DateTimeImmutable($record->last_position_at),
            )
            : null;

        return ShipmentTracking::reconstitute(
            TrackingId::fromString($record->id),
            $record->shipment_id,
            TrackingToken::fromString($record->token),
            VisibleStatus::from($record->status),
            $record->remaining_stops,
            $eta,
            $lastPosition,
        );
    }
}
