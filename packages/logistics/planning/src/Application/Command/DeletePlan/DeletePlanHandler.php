<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\DeletePlan;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, le pide al agregado que se borre (valida que no esté publicado y graba PlanDeleted con
 * los envíos liberados), lo quita de la persistencia junto con sus paradas y publica el evento al outbox.
 *
 * Los pedidos quedan igual que al quitar una parada (RemoveStopFromPlan): un plan sin publicar nunca les
 * cambió el estado (pasan a planificados recién al publicarse), así que basta con que dejen de figurar en
 * una parada para que vuelvan a ser candidatos de otro viaje. No hay nada que revertir en Envíos.
 *
 * El borrado del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<DeletePlan>
 */
final readonly class DeletePlanHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DeletePlan) {
            throw PlanningException::because('DeletePlanHandler solo maneja DeletePlan');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because('El plan indicado no existe.');

        $plan->delete();

        $this->plans->delete($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}
