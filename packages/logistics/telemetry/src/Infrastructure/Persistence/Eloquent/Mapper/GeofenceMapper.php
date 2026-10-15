<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\Polygon;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\GeofenceRecord;

/**
 * Traduce entre el agregado Geofence (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class GeofenceMapper
{
    public function toDomain(GeofenceRecord $record): Geofence
    {
        return Geofence::reconstitute(
            GeofenceId::fromString($record->id),
            $record->name,
            GeofenceType::from($record->type),
            Polygon::fromArray($record->polygon),
            $record->active,
            $record->node_ref,
        );
    }

    /**
     * @return array{id: string, name: string, type: string, polygon: list<array{lat: float, lng: float}>, active: bool, node_ref: string|null}
     */
    public function toRow(Geofence $geofence): array
    {
        return [
            'id' => $geofence->id()->toString(),
            'name' => $geofence->name(),
            'type' => $geofence->type()->value,
            'polygon' => $geofence->polygon()->toArray(),
            'active' => $geofence->isActive(),
            'node_ref' => $geofence->nodeRef(),
        ];
    }
}
