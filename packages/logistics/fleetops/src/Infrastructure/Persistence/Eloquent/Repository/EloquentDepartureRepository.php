<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\DepartureMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\DepartureRecord;

/**
 * Implementación Eloquent del repositorio de salidas. `save` es un upsert por id.
 */
final readonly class EloquentDepartureRepository implements DepartureRepository
{
    public function __construct(
        private DepartureMapper $mapper = new DepartureMapper,
    ) {}

    public function nextIdentity(): DepartureId
    {
        return DepartureId::generate();
    }

    public function save(Departure $departure): void
    {
        $row = $this->mapper->toRow($departure);
        DepartureRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(DepartureId $id): ?Departure
    {
        $record = DepartureRecord::query()->find($id->toString());

        return $record instanceof DepartureRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array {
        $query = DepartureRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($from !== null) {
            $query->where('departed_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('departed_at', '<=', $to);
        }

        return array_values($query
            ->orderByDesc('departed_at')
            ->get()
            ->map(fn (DepartureRecord $record): Departure => $this->mapper->toDomain($record))
            ->all());
    }

    public function openForVehicle(string $vehicleId): ?Departure
    {
        $record = DepartureRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->where('status', DepartureStatus::Out->value)
            ->orderByDesc('departed_at')
            ->first();

        return $record instanceof DepartureRecord ? $this->mapper->toDomain($record) : null;
    }
}
