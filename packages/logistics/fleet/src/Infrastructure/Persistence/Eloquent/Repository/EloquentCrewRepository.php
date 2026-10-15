<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper\CrewMapper;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;

/**
 * Implementación Eloquent del repositorio de tripulaciones. `save` es un upsert por id.
 */
final readonly class EloquentCrewRepository implements CrewRepository
{
    public function __construct(
        private CrewMapper $mapper = new CrewMapper,
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

    public function workShiftsForMemberBetween(string $memberId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = CrewModel::query()
            ->whereNotNull('shift_starts_at')
            ->whereNotNull('shift_ends_at')
            ->whereBetween('assembled_for', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->where(function (Builder $query) use ($memberId): void {
                $query->where('driver_id', $memberId)
                    ->orWhereJsonContains('helper_ids', $memberId);
            })
            ->get();

        $shifts = [];
        foreach ($rows as $row) {
            $start = $row->shift_starts_at;
            $end = $row->shift_ends_at;
            if (is_string($start) && is_string($end)) {
                $shifts[] = WorkShift::fromArray(['starts_at' => $start, 'ends_at' => $end]);
            }
        }

        return $shifts;
    }
}
