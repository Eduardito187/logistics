<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReorderStops;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, reordena sus paradas, lo persiste y publica sus eventos al outbox.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ReorderStops>
 */
final readonly class ReorderStopsHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private ?PlanGeometryRefresher $geometry = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReorderStops) {
            throw PlanningException::because('ReorderStopsHandler solo maneja ReorderStops');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        $plan->reorderStops($command->orderedShipmentIds);

        // El reorden invalida la geometría: se recalcula con el nuevo orden (degrada sin romper).
        $this->geometry?->refresh($plan);

        $this->plans->save($plan);

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}
