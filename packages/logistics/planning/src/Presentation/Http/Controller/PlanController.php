<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Query\GetPlan\GetPlan;
use Logistics\Planning\Application\Query\ListPlansByDate\ListPlansByDate;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Presentation\Http\Request\AssignShipmentRequest;
use Logistics\Planning\Presentation\Http\Request\GeneratePlanProposalRequest;
use Logistics\Planning\Presentation\Http\Request\ReorderStopsRequest;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Puerta HTTP de planificación para la app interna: ver el plan del día, generar la propuesta,
 * mover envíos entre camiones, reordenar la secuencia, publicar y consultar la ETA.
 *
 * Agrupado por recurso (el plan). Cada acción traduce la petición a un comando o consulta y la
 * despacha por el bus correspondiente; nunca toca Infrastructure ni Domain directo. El refresco tras
 * un comando se hace releyendo con la consulta GetPlan, para devolver siempre la forma de lectura.
 */
final class PlanController extends PlanningController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * GET /plans?date=YYYY-MM-DD — planes de reparto de un día (uno por camión).
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'string', 'date_format:Y-m-d'],
        ]);

        /** @var list<PlanView> $plans */
        $plans = $this->queryBus->ask(new ListPlansByDate($validated['date']));

        return $this->collection(
            array_map(static fn (PlanView $plan): array => $plan->toArray(), $plans),
            ['date' => $validated['date'], 'count' => count($plans)],
        );
    }

    /**
     * GET /plans/{planId} — un plan con su secuencia, ETA y plan de carga.
     */
    public function show(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        return $this->resource($plan->toArray());
    }

    /**
     * POST /plans — genera la propuesta automática pedido→camión de un día para un vehículo.
     *
     * El comando es void (el id lo genera el dominio), así que tras despacharlo releemos los planes del
     * día y devolvemos el del vehículo recién planificado.
     */
    public function store(GeneratePlanProposalRequest $request): JsonResponse
    {
        $command = $request->toCommand();
        $this->commandBus->dispatch($command);

        /** @var list<PlanView> $plans */
        $plans = $this->queryBus->ask(new ListPlansByDate($command->planDate));

        foreach ($plans as $plan) {
            if ($plan->vehicleId === $command->vehicleId) {
                return $this->resource($plan->toArray(), 201);
            }
        }

        // No debería ocurrir: el plan acaba de persistirse. Si pasa, se informa como aceptado sin cuerpo.
        return new JsonResponse([
            'data' => ['plan_date' => $command->planDate, 'vehicle_id' => $command->vehicleId],
        ], 202);
    }

    /**
     * POST /plans/{planId}/stops — ajuste manual: agrega (o mueve) un envío a este camión.
     */
    public function assignShipment(AssignShipmentRequest $request, string $planId): JsonResponse
    {
        $this->commandBus->dispatch($request->toCommand($planId));

        return $this->refreshed($planId);
    }

    /**
     * PUT /plans/{planId}/sequence — reordena las paradas del plan al orden indicado.
     */
    public function reorderStops(ReorderStopsRequest $request, string $planId): JsonResponse
    {
        $this->commandBus->dispatch($request->toCommand($planId));

        return $this->refreshed($planId);
    }

    /**
     * POST /plans/{planId}/publish — confirma el plan y lo deja disponible para la ejecución.
     */
    public function publish(string $planId): JsonResponse
    {
        $this->commandBus->dispatch(new PublishPlan($planId));

        return $this->refreshed($planId);
    }

    /**
     * GET /plans/{planId}/eta — ETA por parada del plan (instante y minutos desde la salida).
     */
    public function eta(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        $etas = array_map(static fn ($stop): array => [
            'shipment_id' => $stop->shipmentId,
            'sequence' => $stop->sequence,
            'eta_at' => $stop->etaAt,
            'eta_minutes' => $stop->etaMinutes,
        ], $plan->stops);

        return $this->resource([
            'plan_id' => $plan->id,
            'status' => $plan->status,
            'stops' => $etas,
        ]);
    }

    /**
     * Relee el plan y lo devuelve, o 404 si desapareció. Uso común tras un comando.
     */
    private function refreshed(string $planId): JsonResponse
    {
        $plan = $this->findPlan($planId);

        if ($plan === null) {
            return $this->notFound("plan {$planId} not found");
        }

        return $this->resource($plan->toArray());
    }

    private function findPlan(string $planId): ?PlanView
    {
        /** @var PlanView|null $plan */
        $plan = $this->queryBus->ask(new GetPlan($planId));

        return $plan;
    }
}
