<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\RemoveStopFromPlan;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, le quita la parada del envío (el agregado renumera la secuencia y graba
 * StopRemovedFromPlan), recalcula la geometría, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<RemoveStopFromPlan>
 */
final readonly class RemoveStopFromPlanHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private ?PlanGeometryRefresher $geometry = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RemoveStopFromPlan) {
            throw PlanningException::because('RemoveStopFromPlanHandler solo maneja RemoveStopFromPlan');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because('El plan indicado no existe.');

        $plan->removeStop($command->shipmentId);

        // Quitar una parada cambia el recorrido: se recalcula la geometría (degrada sin romper).
        $this->geometry?->refresh($plan);

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}
