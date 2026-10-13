<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReorderStops;

use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, reordena sus paradas, lo persiste y libera sus eventos.
 *
 * @implements CommandHandler<ReorderStops>
 */
final readonly class ReorderStopsHandler implements CommandHandler
{
    public function __construct(private PlanRepository $plans) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReorderStops) {
            throw PlanningException::because('ReorderStopsHandler solo maneja ReorderStops');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        $plan->reorderStops($command->orderedShipmentIds);

        $this->plans->save($plan);

        // TODO(outbox): despachar al outbox dentro de la misma transacción al integrar.
        $plan->releaseEvents();
    }
}
