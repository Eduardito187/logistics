<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Planning\Application\Query\GetDailyPlanRun\GetDailyPlanRun;
use Logistics\Planning\Application\ReadModel\AutoPlanRunView;
use Logistics\Planning\Presentation\Http\Request\GenerateDailyPlanRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta HTTP del AUTO-PLANIFICADOR del día para la app interna: dispara la generación automática
 * (devuelve la corrida encolada) y consulta su estado/resultado.
 *
 * La generación es ASÍNCRONA: el POST solo encola el trabajo y responde 202 con la corrida; el cálculo
 * de camiones y rutas lo hace el worker. El cliente consulta el avance con el GET.
 */
final class AutoPlanController extends PlanningController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * POST /plans/auto — dispara la planificación automática del día (modo optimization|slots).
     *
     * El comando es void; tras despacharlo releemos la corrida del día+modo y la devolvemos como
     * recurso aceptado (202). Idempotente: re-disparar el mismo día+modo devuelve la corrida existente.
     */
    public function store(GenerateDailyPlanRequest $request): JsonResponse
    {
        $command = $request->toCommand();
        $this->commandBus->dispatch($command);

        $run = $this->findRun($command->planDate, $command->strategy);

        if ($run === null) {
            // No debería ocurrir: la corrida acaba de persistirse.
            return new JsonResponse([
                'data' => ['plan_date' => $command->planDate, 'strategy' => $command->strategy, 'status' => 'queued'],
            ], 202);
        }

        return $this->resource($run->toArray(), 202);
    }

    /**
     * GET /plans/auto?date=YYYY-MM-DD&strategy=optimization|slots — estado/resultado de la corrida.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d'],
            'strategy' => ['required', 'string', 'in:optimization,slots'],
        ]);

        $run = $this->findRun($validated['date'], $validated['strategy']);

        if ($run === null) {
            return $this->notFound("no auto-plan run found for {$validated['date']} ({$validated['strategy']})");
        }

        return $this->resource($run->toArray());
    }

    private function findRun(string $planDate, string $strategy): ?AutoPlanRunView
    {
        /** @var AutoPlanRunView|null $run */
        $run = $this->queryBus->ask(new GetDailyPlanRun($planDate, $strategy));

        return $run;
    }
}
