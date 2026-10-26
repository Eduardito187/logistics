<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    public function listBy(?string $vehicleId, ?DateTimeImmutable $day, ?int $limit = null, ?int $offset = null): array
    {
        $query = $this->filtered($vehicleId, $day)->orderByDesc('started_at');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (TripRecord $record): Trip => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(?string $vehicleId, ?DateTimeImmutable $day): int
    {
        return $this->filtered($vehicleId, $day)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<TripRecord>
     */
    private function filtered(?string $vehicleId, ?DateTimeImmutable $day): Builder
    {
        $query = TripRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($day !== null) {
            // `started_at` es timestamptz: `whereDate()` envuelve la columna en DATE(...) y anula el
            // índice (scan completo). Se filtra por rango semiabierto [día, día+1) para que el índice
            // sobre `started_at` entre en juego (M-2 de la auditoría de performance 2026-10-20).
            $dayStart = $day->setTime(0, 0, 0);
            $nextDay = $dayStart->modify('+1 day');
            $query
                ->where('started_at', '>=', $dayStart->format('Y-m-d H:i:s'))
                ->where('started_at', '<', $nextDay->format('Y-m-d H:i:s'));
        }

        return $query;
    }
}
