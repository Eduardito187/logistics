<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\PreTripInspectionMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;

/**
 * Implementación Eloquent del repositorio de inspecciones pre-salida. `save` es un upsert por id.
 */
final readonly class EloquentPreTripInspectionRepository implements PreTripInspectionRepository
{
    public function __construct(
        private PreTripInspectionMapper $mapper = new PreTripInspectionMapper,
    ) {}

    public function nextIdentity(): PreTripInspectionId
    {
        return PreTripInspectionId::generate();
    }

    public function save(PreTripInspection $inspection): void
    {
        $row = $this->mapper->toRow($inspection);
        PreTripInspectionModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(PreTripInspectionId $id): ?PreTripInspection
    {
        $model = PreTripInspectionModel::query()->find($id->toString());

        return $model instanceof PreTripInspectionModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(PreTripInspectionModel::query()
            ->orderBy('performed_on', 'desc')
            ->get()
            ->map(fn (PreTripInspectionModel $model): PreTripInspection => $this->mapper->toDomain($model))
            ->all());
    }

    public function listBy(
        ?string $vehicleId = null,
        ?bool $onlyPassed = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Orden determinista para paginar: fecha desc y, a igual fecha, id desc (UUID v7 = más reciente).
        $query = $this->filtered($vehicleId, $onlyPassed, $from, $to)
            ->orderByDesc('performed_on')
            ->orderByDesc('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (PreTripInspectionModel $model): PreTripInspection => $this->mapper->toDomain($model))
            ->all());
    }

    public function countBy(
        ?string $vehicleId = null,
        ?bool $onlyPassed = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int {
        return $this->filtered($vehicleId, $onlyPassed, $from, $to)->count();
    }

    public function latestForVehicle(string $vehicleId): ?PreTripInspection
    {
        $model = PreTripInspectionModel::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('performed_on')
            ->orderByDesc('id')
            ->first();

        return $model instanceof PreTripInspectionModel ? $this->mapper->toDomain($model) : null;
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<PreTripInspectionModel>
     */
    private function filtered(
        ?string $vehicleId,
        ?bool $onlyPassed,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): Builder {
        $query = PreTripInspectionModel::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($onlyPassed !== null) {
            $query->where('passed', $onlyPassed);
        }
        if ($from !== null) {
            $query->where('performed_on', '>=', $from->format('Y-m-d'));
        }
        if ($to !== null) {
            $query->where('performed_on', '<=', $to->format('Y-m-d'));
        }

        return $query;
    }
}
