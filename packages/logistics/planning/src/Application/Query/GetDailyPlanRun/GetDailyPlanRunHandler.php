<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\GetDailyPlanRun;

use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la vista de la última corrida del auto-planificador para un día+modo, o null si no hubo
 * ninguna.
 *
 * @implements QueryHandler<GetDailyPlanRun, AutoPlanRunView|null>
 */
final readonly class GetDailyPlanRunHandler implements QueryHandler
{
    public function __construct(
        private AutoPlanRunRepository $runs,
    ) {}

    public function handle(Query $query): ?AutoPlanRunView
    {
        if (!$query instanceof GetDailyPlanRun) {
            throw PlanningException::because('GetDailyPlanRunHandler solo maneja GetDailyPlanRun');
        }

        $run = $this->runs->latestFor(
            PlanDate::fromString($query->planDate),
            PlanStrategy::fromString($query->strategy),
        );

        return $run === null ? null : AutoPlanRunView::fromDomain($run);
    }
}
