<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GenerateDailyPlan;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\AutoPlanSettingsProvider;
use Logistics\Planning\Domain\Contract\DailyPlanDispatcher;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Dispara la planificación automática del día SIN hacer el cálculo pesado: resuelve los parámetros
 * configurables, abre una corrida encolada (idempotente por día+modo) y delega el trabajo a la cola.
 *
 * Idempotencia: si ya hay una corrida ACTIVA o completada para el mismo día+modo, no abre otra ni
 * reencola; deja el id de esa corrida como resultado. Solo tras una corrida fallida (o si no hay
 * ninguna) se encola una nueva.
 *
 * El encolado real lo hace el DailyPlanDispatcher DESPUÉS del commit (el save de la corrida y el
 * append al outbox son atómicos vía el CommandBus transaccional); así el worker nunca arranca sobre un
 * estado aún no confirmado.
 *
 * Deja el id de la corrida en lastRunId() para que el llamador lo devuelva o consulte.
 *
 * @implements CommandHandler<GenerateDailyPlan>
 */
final class GenerateDailyPlanHandler implements CommandHandler
{
    private ?string $lastRunId = null;

    public function __construct(
        private readonly AutoPlanRunRepository $runs,
        private readonly AutoPlanSettingsProvider $settings,
        private readonly DailyPlanDispatcher $dispatcher,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof GenerateDailyPlan) {
            throw PlanningException::because('GenerateDailyPlanHandler solo maneja GenerateDailyPlan');
        }

        $date = PlanDate::fromString($command->planDate);
        $strategy = PlanStrategy::fromString($command->strategy);

        // Idempotencia: una corrida activa o completada bloquea re-disparar el mismo día+modo.
        $existing = $this->runs->activeFor($date, $strategy);
        if ($existing !== null) {
            $this->lastRunId = $existing->id()->toString();

            return;
        }

        $params = $this->settings->parametersFor($date, $strategy, $command->params);

        $run = AutoPlanRun::queue($this->runs->nextIdentity(), $date, $strategy, $this->clock->now());
        $this->runs->save($run);
        $this->recordEvents->record($run->releaseEvents(), 'planning.auto_plan_run');

        // El cálculo pesado corre asíncrono: se encola después del commit.
        $this->dispatcher->dispatch($run->id(), $date, $strategy, $params);

        $this->lastRunId = $run->id()->toString();
    }

    /**
     * Id de la corrida disparada (o de la ya existente, por idempotencia) tras el último handle().
     */
    public function lastRunId(): string
    {
        if ($this->lastRunId === null) {
            throw PlanningException::because('todavía no se disparó ninguna corrida en este handler');
        }

        return $this->lastRunId;
    }
}
