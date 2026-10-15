<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunStatus;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;

/**
 * Implementación Eloquent del repositorio de corridas del auto-planificador. save() es un upsert por id.
 */
final class EloquentAutoPlanRunRepository implements AutoPlanRunRepository
{
    public function nextIdentity(): AutoPlanRunId
    {
        return AutoPlanRunId::generate();
    }

    public function save(AutoPlanRun $run): void
    {
        AutoPlanRunRecord::query()->updateOrCreate(
            ['id' => $run->id()->toString()],
            [
                'plan_date' => $run->date()->toString(),
                'strategy' => $run->strategy()->value,
                'status' => $run->status()->value,
                'requested_at' => $run->requestedAt()->format(DATE_ATOM),
                'completed_at' => $run->completedAt()?->format(DATE_ATOM),
                'trucks_planned' => $run->trucksPlanned(),
                'stops_planned' => $run->stopsPlanned(),
                'unassigned_count' => $run->unassignedCount(),
                'failure_reason' => $run->failureReason(),
            ],
        );
    }

    public function findById(AutoPlanRunId $id): ?AutoPlanRun
    {
        $record = AutoPlanRunRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function activeFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun
    {
        $record = AutoPlanRunRecord::query()
            ->where('plan_date', $date->toString())
            ->where('strategy', $strategy->value)
            ->whereIn('status', [
                AutoPlanRunStatus::Queued->value,
                AutoPlanRunStatus::Processing->value,
                AutoPlanRunStatus::Completed->value,
            ])
            ->orderByDesc('requested_at')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function latestFor(PlanDate $date, PlanStrategy $strategy): ?AutoPlanRun
    {
        $record = AutoPlanRunRecord::query()
            ->where('plan_date', $date->toString())
            ->where('strategy', $strategy->value)
            ->orderByDesc('requested_at')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(AutoPlanRunRecord $record): AutoPlanRun
    {
        return AutoPlanRun::reconstitute(
            AutoPlanRunId::fromString($record->id),
            PlanDate::fromString(substr($record->plan_date, 0, 10)),
            PlanStrategy::from($record->strategy),
            AutoPlanRunStatus::from($record->status),
            new DateTimeImmutable($record->requested_at),
            $record->completed_at !== null ? new DateTimeImmutable($record->completed_at) : null,
            $record->trucks_planned,
            $record->stops_planned,
            $record->unassigned_count,
            $record->failure_reason,
        );
    }
}
