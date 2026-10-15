<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListExpenses;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\ExpenseView;
use Logistics\FleetOps\Domain\Contract\ExpenseRepository;
use Logistics\FleetOps\Domain\Model\Expense;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de gastos (filtrada) como proyecciones de lectura.
 *
 * @implements QueryHandler<ListExpenses, list<ExpenseView>>
 */
final readonly class ListExpensesHandler implements QueryHandler
{
    public function __construct(
        private ExpenseRepository $expenses,
    ) {}

    /**
     * @return list<ExpenseView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListExpenses);

        $category = $query->category !== null ? ExpenseCategory::from($query->category) : null;
        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $expenses = $this->expenses->listBy($query->vehicleId, $category, $from, $to);

        return array_map(static fn (Expense $expense): ExpenseView => ExpenseView::fromDomain($expense), $expenses);
    }
}
