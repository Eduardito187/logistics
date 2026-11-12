<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Support\Facades\Date;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\StopEtaRecord;

/**
 * Implementación Eloquent de la proyección de ETAs por parada. `replaceForVehicle` borra las filas del
 * vehículo y escribe las nuevas (upsert por shipment_id: un envío reasignado a otro vehículo se mueve).
 */
final readonly class EloquentStopEtaRepository implements StopEtaRepository
{
    public function replaceForVehicle(string $vehicleId, array $rows): void
    {
        StopEtaRecord::query()->where('vehicle_id', $vehicleId)->delete();

        if ($rows === []) {
            return;
        }

        $now = Date::now();
        $payload = array_map(static fn (array $row): array => $row + ['created_at' => $now, 'updated_at' => $now], $rows);

        StopEtaRecord::query()->upsert(
            $payload,
            ['shipment_id'],
            ['vehicle_id', 'sequence', 'stop_lat', 'stop_lng', 'eta_at', 'remaining_distance_m', 'remaining_duration_s', 'method', 'position_recorded_at', 'computed_at', 'updated_at'],
        );
    }

    public function forVehicle(string $vehicleId): array
    {
        return array_values(StopEtaRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (StopEtaRecord $record): array => [
                'shipment_id' => $record->shipment_id,
                'vehicle_id' => $record->vehicle_id,
                'sequence' => $record->sequence,
                'stop_lat' => $record->stop_lat,
                'stop_lng' => $record->stop_lng,
                'eta_at' => $record->eta_at?->toDateTimeImmutable(),
                'remaining_distance_m' => $record->remaining_distance_m,
                'remaining_duration_s' => $record->remaining_duration_s,
                'method' => $record->method,
                'position_recorded_at' => $record->position_recorded_at->toDateTimeImmutable(),
                'computed_at' => $record->computed_at->toDateTimeImmutable(),
            ])
            ->all());
    }
}
