<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Logistics\FleetOps\Domain\Contract\RefuelRepository;
use Logistics\FleetOps\Domain\Model\Refuel;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\RefuelMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;

/**
 * Implementación Eloquent del repositorio de cargas de combustible. `save` es un upsert por id.
 */
final readonly class EloquentRefuelRepository implements RefuelRepository
{
    public function __construct(
        private RefuelMapper $mapper = new RefuelMapper,
    ) {}

    public function nextIdentity(): RefuelId
    {
        return RefuelId::generate();
    }

    public function save(Refuel $refuel): void
    {
        $row = $this->mapper->toRow($refuel);
        RefuelRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(RefuelId $id): ?Refuel
    {
        $record = RefuelRecord::query()->find($id->toString());

        return $record instanceof RefuelRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(?string $vehicleId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?int $limit = null, ?int $offset = null): array
    {
        // Orden determinista para la paginación: refueled_at desc con desempate por id (UUID v7).
        $query = $this->filtered($vehicleId, $from, $to)
            ->orderByDesc('refueled_at')
            ->orderByDesc('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (RefuelRecord $record): Refuel => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(?string $vehicleId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): int
    {
        return $this->filtered($vehicleId, $from, $to)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<RefuelRecord>
     */
    private function filtered(?string $vehicleId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): Builder
    {
        $query = RefuelRecord::query();
        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($from !== null) {
            $query->where('refueled_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('refueled_at', '<=', $to);
        }

        return $query;
    }

    public function forVehicleOrdered(string $vehicleId): array
    {
        return array_values(RefuelRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderBy('refueled_at')
            ->get()
            ->map(fn (RefuelRecord $record): Refuel => $this->mapper->toDomain($record))
            ->all());
    }
}
