<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\PublishPlan;

use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el plan, lo publica, lo persiste y libera el evento PlanPublished.
 *
 * @implements CommandHandler<PublishPlan>
 */
final readonly class PublishPlanHandler implements CommandHandler
{
    public function __construct(private PlanRepository $plans) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof PublishPlan) {
            throw PlanningException::because('PublishPlanHandler solo maneja PublishPlan');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        $plan->publish();

        $this->plans->save($plan);

        // TODO(outbox): despachar PlanPublished al outbox dentro de la misma transacción al integrar.
        $plan->releaseEvents();
    }
}
