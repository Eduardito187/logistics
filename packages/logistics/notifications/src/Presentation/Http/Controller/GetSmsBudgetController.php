<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Logistics\Notifications\Application\Query\GetSmsBudget\GetSmsBudget;
use Logistics\Notifications\Application\ReadModel\SmsBudgetView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el presupuesto de SMS del período (puerta app). Sin `period` usa el mes actual.
 */
final readonly class GetSmsBudgetController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->query('period');
        $period = is_string($period) && $period !== '' ? $period : null;

        if ($period !== null) {
            Validator::make(
                ['period' => $period],
                ['period' => ['regex:/^\d{4}-\d{2}$/']],
            )->validate();
        }

        /** @var SmsBudgetView $view */
        $view = $this->queryBus->ask(new GetSmsBudget($period));

        return new JsonResponse(['data' => $view->toArray()]);
    }
}
