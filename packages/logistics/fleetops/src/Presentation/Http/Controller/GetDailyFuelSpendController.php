<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\FleetOps\Application\Query\GetDailyFuelSpend\GetDailyFuelSpend;
use Logistics\FleetOps\Application\ReadModel\DailyFuelSpendView;
use Logistics\FleetOps\Presentation\Http\ApiController;
use Logistics\FleetOps\Presentation\Http\Request\GetDailyFuelSpendRequest;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el gasto de combustible de un día concreto (consulta GetDailyFuelSpend).
 */
final class GetDailyFuelSpendController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(GetDailyFuelSpendRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var DailyFuelSpendView $view */
            $view = $this->queries->ask(new GetDailyFuelSpend(
                date: $request->string('date')->toString(),
            ));

            return $this->data($view->toArray());
        });
    }
}
