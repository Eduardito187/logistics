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
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve la lista de gastos (filtrada) como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa, por compatibilidad.
 *
 * @implements QueryHandler<ListExpenses, list<ExpenseView>|Page<ExpenseView>>
 */
final readonly class ListExpensesHandler implements QueryHandler
{
    public function __construct(
        private ExpenseRepository $expenses,
    ) {}

    /**
     * @return list<ExpenseView>|Page<ExpenseView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListExpenses);

        $category = $query->category !== null ? ExpenseCategory::from($query->category) : null;
        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (Expense $expense): ExpenseView => ExpenseView::fromDomain($expense),
            $this->expenses->listBy($query->vehicleId, $category, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->expenses->countBy($query->vehicleId, $category, $from, $to));
    }
}
