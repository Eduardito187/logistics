<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\ListExpenses\ListExpenses;
use Logistics\FleetOps\Application\ReadModel\ExpenseView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\ListExpensesRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista gastos de flota con filtros opcionales por vehículo, categoría y rango de fechas (ListExpenses).
 */
final class ListExpensesController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(ListExpensesRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<ExpenseView> $expenses */
            $expenses = $this->queries->ask(new ListExpenses(
                vehicleId: $request->filled('vehicle_id') ? $request->string('vehicle_id')->toString() : null,
                category: $request->filled('category') ? $request->string('category')->toString() : null,
                from: $request->filled('from') ? $request->string('from')->toString() : null,
                to: $request->filled('to') ? $request->string('to')->toString() : null,
            ));

            return $this->data(
                array_map(static fn (ExpenseView $view): array => $view->toArray(), $expenses),
            );
        });
    }
}
