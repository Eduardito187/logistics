<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetPlan;

use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetPlan, ?PlanView>
 */
final readonly class GetPlanHandler implements QueryHandler
{
    public function __construct(private PlanRepository $plans) {}

    public function handle(Query $query): ?PlanView
    {
        if (!$query instanceof GetPlan) {
            throw PlanningException::because('GetPlanHandler solo maneja GetPlan');
        }

        $plan = $this->plans->findById(PlanId::fromString($query->planId));

        return $plan === null ? null : PlanView::fromPlan($plan);
    }
}
