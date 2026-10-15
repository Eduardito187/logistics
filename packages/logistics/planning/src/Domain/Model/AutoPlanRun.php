<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Model;

use DateTimeImmutable;
use Logistics\Planning\Domain\Event\DailyPlanGenerated;
use Logistics\Planning\Domain\Event\DailyPlanRequested;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunStatus;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado que gobierna una corrida del auto-planificador diario: su ciclo de vida asíncrono
 * (Queued → Processing → Completed | Failed) y el resumen del resultado.
 *
 * El comando la deja encolada (queue) y emite planning.auto_plan_requested; un worker la toma
 * (markProcessing) y la cierra con complete() —que emite planning.plan_generated con el resumen— o con
 * fail(). El estado da la idempotencia: una corrida activa o completada no se vuelve a disparar.
 */
final class AutoPlanRun extends AggregateRoot
{
    private function __construct(
        private readonly AutoPlanRunId $id,
        private readonly PlanDate $date,
        private readonly PlanStrategy $strategy,
        private AutoPlanRunStatus $status,
        private readonly DateTimeImmutable $requestedAt,
        private ?DateTimeImmutable $completedAt,
        private int $trucksPlanned,
        private int $stopsPlanned,
        private int $unassignedCount,
        private ?string $failureReason,
    ) {}

    /**
     * Abre una corrida ENCOLADA para un día y un modo, y registra el hecho del disparo.
     */
    public static function queue(
        AutoPlanRunId $id,
        PlanDate $date,
        PlanStrategy $strategy,
        DateTimeImmutable $requestedAt,
    ): self {
        $run = new self(
            $id,
            $date,
            $strategy,
            AutoPlanRunStatus::Queued,
            $requestedAt,
            null,
            0,
            0,
            0,
            null,
        );

        $run->recordEvent(new DailyPlanRequested($id->toString(), $date->toString(), $strategy->value));

        return $run;
    }

    /**
     * Reconstituye la corrida desde la persistencia, sin registrar eventos.
     */
    public static function reconstitute(
        AutoPlanRunId $id,
        PlanDate $date,
        PlanStrategy $strategy,
        AutoPlanRunStatus $status,
        DateTimeImmutable $requestedAt,
        ?DateTimeImmutable $completedAt,
        int $trucksPlanned,
        int $stopsPlanned,
        int $unassignedCount,
        ?string $failureReason,
    ): self {
        return new self(
            $id,
            $date,
            $strategy,
            $status,
            $requestedAt,
            $completedAt,
            $trucksPlanned,
            $stopsPlanned,
            $unassignedCount,
            $failureReason,
        );
    }

    /**
     * El worker tomó la corrida: pasa a Processing. Solo desde Queued (reintentos sobre una corrida ya
     * en proceso no reabren el estado).
     */
    public function markProcessing(): void
    {
        if ($this->status !== AutoPlanRunStatus::Queued) {
            throw PlanningException::because("solo se puede procesar una corrida encolada (estado actual: {$this->status->value})");
        }

        $this->status = AutoPlanRunStatus::Processing;
    }

    /**
     * Cierra la corrida con éxito y su resumen; emite planning.plan_generated.
     */
    public function complete(int $trucksPlanned, int $stopsPlanned, int $unassignedCount, DateTimeImmutable $at): void
    {
        if ($this->status === AutoPlanRunStatus::Completed) {
            return;
        }
        if ($this->status === AutoPlanRunStatus::Queued) {
            throw PlanningException::because('no se puede completar una corrida que no empezó a procesarse');
        }

        $this->status = AutoPlanRunStatus::Completed;
        $this->completedAt = $at;
        $this->trucksPlanned = max(0, $trucksPlanned);
        $this->stopsPlanned = max(0, $stopsPlanned);
        $this->unassignedCount = max(0, $unassignedCount);
        $this->failureReason = null;

        $this->recordEvent(new DailyPlanGenerated(
            $this->id->toString(),
            $this->date->toString(),
            $this->strategy->value,
            $this->trucksPlanned,
            $this->stopsPlanned,
            $this->unassignedCount,
        ));
    }

    /**
     * Marca la corrida como fallida con un motivo legible. No emite evento de negocio.
     */
    public function fail(string $reason, DateTimeImmutable $at): void
    {
        $this->status = AutoPlanRunStatus::Failed;
        $this->completedAt = $at;
        $this->failureReason = trim($reason) !== '' ? trim($reason) : 'unknown error';
    }

    public function id(): AutoPlanRunId
    {
        return $this->id;
    }

    public function date(): PlanDate
    {
        return $this->date;
    }

    public function strategy(): PlanStrategy
    {
        return $this->strategy;
    }

    public function status(): AutoPlanRunStatus
    {
        return $this->status;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function trucksPlanned(): int
    {
        return $this->trucksPlanned;
    }

    public function stopsPlanned(): int
    {
        return $this->stopsPlanned;
    }

    public function unassignedCount(): int
    {
        return $this->unassignedCount;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
