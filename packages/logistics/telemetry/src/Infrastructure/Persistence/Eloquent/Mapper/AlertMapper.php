<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertId;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;

/**
 * Traduce entre el agregado TelemetryAlert (dominio) y su fila Eloquent. Único punto que conoce ambos.
 *
 * Las fechas se persisten como texto ISO 8601 (ATOM) y se reconstruyen a DateTimeImmutable, para no
 * acoplar el dominio a Carbon. El punto geográfico es opcional: existe solo si hay lat y lng.
 */
final class AlertMapper
{
    public function toDomain(AlertRecord $record): TelemetryAlert
    {
        $point = $record->lat !== null && $record->lng !== null
            ? GeoPoint::of($record->lat, $record->lng)
            : null;

        return TelemetryAlert::reconstitute(
            AlertId::fromString($record->id),
            $record->vehicle_id,
            $record->driver_id,
            AlertType::from($record->type),
            AlertSeverity::from($record->severity),
            $point,
            $record->detail,
            new DateTimeImmutable($record->occurred_at),
            $record->acknowledged_at !== null ? new DateTimeImmutable($record->acknowledged_at) : null,
            $record->acknowledged_by,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, type: string, severity: string, lat: float|null, lng: float|null, detail: array<string, mixed>, occurred_at: string, acknowledged_at: string|null, acknowledged_by: string|null}
     */
    public function toRow(TelemetryAlert $alert): array
    {
        $point = $alert->point();
        $acknowledgedAt = $alert->acknowledgedAt();

        return [
            'id' => $alert->id()->toString(),
            'vehicle_id' => $alert->vehicleId(),
            'driver_id' => $alert->driverId(),
            'type' => $alert->type()->value,
            'severity' => $alert->severity()->value,
            'lat' => $point?->lat,
            'lng' => $point?->lng,
            'detail' => $alert->detail(),
            'occurred_at' => $alert->occurredAt()->format(DateTimeImmutable::ATOM),
            'acknowledged_at' => $acknowledgedAt?->format(DateTimeImmutable::ATOM),
            'acknowledged_by' => $alert->acknowledgedBy(),
        ];
    }
}
