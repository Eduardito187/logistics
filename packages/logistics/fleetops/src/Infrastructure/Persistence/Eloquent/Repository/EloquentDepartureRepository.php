<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Orden determinista para paginar: departed_at desc con desempate por id (UUID v7).
        $query = $this->filtered($vehicleId, $status, $from, $to)
            ->orderByDesc('departed_at')
            ->orderByDesc('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (DepartureRecord $record): Departure => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int {
        return $this->filtered($vehicleId, $status, $from, $to)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<DepartureRecord>
     */
    private function filtered(
        ?string $vehicleId,
        ?string $status,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): Builder {
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

        return $query;
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
