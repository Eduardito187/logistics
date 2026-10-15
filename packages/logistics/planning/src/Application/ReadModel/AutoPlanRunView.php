<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

use Logistics\Planning\Domain\Model\AutoPlanRun;

/**
 * Modelo de lectura de una corrida del auto-planificador: forma plana en primitivos para la API y el
 * admin. No expone el agregado de dominio.
 */
final readonly class AutoPlanRunView
{
    public function __construct(
        public string $id,
        public string $planDate,
        public string $strategy,
        public string $status,
        public string $requestedAt,
        public ?string $completedAt,
        public int $trucksPlanned,
        public int $stopsPlanned,
        public int $unassignedCount,
        public ?string $failureReason,
    ) {}

    public static function fromDomain(AutoPlanRun $run): self
    {
        $completedAt = $run->completedAt();

        return new self(
            $run->id()->toString(),
            $run->date()->toString(),
            $run->strategy()->value,
            $run->status()->value,
            $run->requestedAt()->format(DATE_ATOM),
            $completedAt?->format(DATE_ATOM),
            $run->trucksPlanned(),
            $run->stopsPlanned(),
            $run->unassignedCount(),
            $run->failureReason(),
        );
    }

    /**
     * @return array{id: string, plan_date: string, strategy: string, status: string, requested_at: string, completed_at: string|null, trucks_planned: int, stops_planned: int, unassigned_count: int, failure_reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'plan_date' => $this->planDate,
            'strategy' => $this->strategy,
            'status' => $this->status,
            'requested_at' => $this->requestedAt,
            'completed_at' => $this->completedAt,
            'trucks_planned' => $this->trucksPlanned,
            'stops_planned' => $this->stopsPlanned,
            'unassigned_count' => $this->unassignedCount,
            'failure_reason' => $this->failureReason,
        ];
    }
}
