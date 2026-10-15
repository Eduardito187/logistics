<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

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
}
