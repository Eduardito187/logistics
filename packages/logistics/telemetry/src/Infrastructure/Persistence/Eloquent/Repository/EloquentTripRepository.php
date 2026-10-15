<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\TripMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\TripRecord;

/**
 * Implementación Eloquent del repositorio de trips. `save` es un upsert por id.
 */
final readonly class EloquentTripRepository implements TripRepository
{
    public function __construct(
        private TripMapper $mapper = new TripMapper,
    ) {}

    public function nextIdentity(): TripId
    {
        return TripId::generate();
    }

    public function save(Trip $trip): void
    {
        $row = $this->mapper->toRow($trip);
        TripRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(TripId $id): ?Trip
    {
        $record = TripRecord::query()->find($id->toString());

        return $record instanceof TripRecord ? $this->mapper->toDomain($record) : null;
    }

    public function activeForVehicle(string $vehicleId): ?Trip
    {
        $record = TripRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->where('status', TripStatus::Active->value)
            ->orderByDesc('started_at')
            ->first();

        return $record instanceof TripRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(TripRecord::query()
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (TripRecord $record): Trip => $this->mapper->toDomain($record))
            ->all());
    }

    public function listBy(?string $vehicleId, ?DateTimeImmutable $day): array
    {
        $query = TripRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($day !== null) {
            $query->whereDate('started_at', $day->format('Y-m-d'));
        }

        return array_values($query
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (TripRecord $record): Trip => $this->mapper->toDomain($record))
            ->all());
    }
}
