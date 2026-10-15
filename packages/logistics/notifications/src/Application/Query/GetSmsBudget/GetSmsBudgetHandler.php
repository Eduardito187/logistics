<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetSmsBudget;

use Logistics\Notifications\Application\ReadModel\SmsBudgetView;
use Logistics\Notifications\Domain\Contract\SmsBudgetRepository;
use Logistics\Notifications\Domain\Exception\NotificationsException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Devuelve el presupuesto de SMS del período pedido (o el mes actual). El tope lo aporta la
 * configuración del package (inyectado), nunca un valor mágico en el dominio.
 *
 * @implements QueryHandler<GetSmsBudget, SmsBudgetView>
 */
final class GetSmsBudgetHandler implements QueryHandler
{
    public function __construct(
        private readonly SmsBudgetRepository $budgets,
        private readonly Clock $clock,
        private readonly int $monthlyCap,
    ) {}

    public function handle(Query $query): SmsBudgetView
    {
        if (!$query instanceof GetSmsBudget) {
            throw NotificationsException::because('GetSmsBudgetHandler solo maneja GetSmsBudget');
        }

        $period = $query->period ?? $this->clock->now()->format('Y-m');

        return SmsBudgetView::fromBudget($this->budgets->current($period, $this->monthlyCap));
    }
}
