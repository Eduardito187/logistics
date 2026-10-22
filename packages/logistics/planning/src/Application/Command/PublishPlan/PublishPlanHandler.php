<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\PublishPlan;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, lo publica, lo persiste y publica el evento PlanPublished al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<PublishPlan>
 */
final readonly class PublishPlanHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private ?PlanGeometryRefresher $geometry = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof PublishPlan) {
            throw PlanningException::because('PublishPlanHandler solo maneja PublishPlan');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        // Un plan armado a mano o por propuesta puede no tener geometría: se calcula ANTES de publicar
        // (después queda congelado). Un fallo del proveedor no impide publicar.
        $this->geometry?->refresh($plan, onlyIfMissing: true);

        $plan->publish();

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}
