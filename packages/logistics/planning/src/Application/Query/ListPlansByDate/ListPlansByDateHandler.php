<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListPlansByDate;

use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los planes de un día como modelos de lectura.
 *
 * @implements QueryHandler<ListPlansByDate, list<PlanView>>
 */
final readonly class ListPlansByDateHandler implements QueryHandler
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * @return list<PlanView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListPlansByDate) {
            throw PlanningException::because('ListPlansByDateHandler solo maneja ListPlansByDate');
        }

        $plans = $this->plans->findByDate(PlanDate::fromString($query->planDate));

        return array_map(PlanView::fromPlan(...), $plans);
    }
}
