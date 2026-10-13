<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\CrewMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;

/**
 * Implementación Eloquent del repositorio de tripulaciones. `save` es un upsert por id.
 */
final readonly class EloquentCrewRepository implements CrewRepository
{
    public function __construct(
        private CrewMapper $mapper = new CrewMapper(),
    ) {}

    public function nextIdentity(): CrewId
    {
        return CrewId::generate();
    }

    public function save(Crew $crew): void
    {
        $row = $this->mapper->toRow($crew);
        CrewModel::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(CrewId $id): ?Crew
    {
        $model = CrewModel::query()->find($id->toString());

        return $model instanceof CrewModel ? $this->mapper->toDomain($model) : null;
    }

    public function all(): array
    {
        return array_values(CrewModel::query()
            ->orderBy('assembled_for')
            ->get()
            ->map(fn (CrewModel $model): Crew => $this->mapper->toDomain($model))
            ->all());
    }
}
