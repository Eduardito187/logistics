<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use DateTimeImmutable;
use Exception;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Domain\Contract\AutoPlanRunRepository;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PendingDeliveryProvider;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\AutoPlanRun;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunId;
use Logistics\Planning\Domain\ValueObject\AutoPlanRunStatus;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Cálculo PESADO del auto-planificador, el que corre dentro del worker (nunca en un request de usuario):
 * lee los pedidos pendientes y los camiones disponibles, y delega el ruteo al BACKEND configurado
 * (builtin Clarke-Wright+2-opt por defecto, u osrm_trip, o vroom). El backend asigna paradas a camiones
 * (respetando capacidad, tripulación y la regla "un camión = un departamento"), resuelve el orden, la ETA
 * y la geometría por calles de cada ruta; el runner persiste un plan propuesto por camión con todo eso.
 *
 * Idempotente: una corrida ya completada no se re-procesa; y, dentro de un reintento, cada camión reusa el
 * plan existente del día (no duplica) y respeta los publicados. Al terminar, cierra la corrida con su
 * resumen, lo que emite planning.plan_generated al outbox.
 */
final readonly class DailyPlanRunner
{
    public function __construct(
        private AutoPlanRunRepository $runs,
        private PendingDeliveryProvider $pending,
        private FleetAvailabilityProvider $fleet,
        private RoutingBackend $backend,
        private PlanningOriginLocator $origin,
        private PlanRepository $plans,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    /**
     * Ejecuta la corrida identificada por $runId para el día y los parámetros dados. Tolerante a
     * reentregas de la cola: si la corrida no existe o ya se completó, no hace nada.
     */
    public function run(AutoPlanRunId $runId, PlanDate $date, AutoPlanParameters $params): void
    {
        $run = $this->runs->findById($runId);
        if ($run === null || $run->status() === AutoPlanRunStatus::Completed) {
            return;
        }

        try {
            $this->process($run, $date, $params);
        } catch (Exception $exception) {
            $run->fail($exception->getMessage(), $this->clock->now());
            $this->runs->save($run);

            throw $exception;
        }
    }

    private function process(AutoPlanRun $run, PlanDate $date, AutoPlanParameters $params): void
    {
        if ($run->status() === AutoPlanRunStatus::Queued) {
            $run->markProcessing();
            $this->runs->save($run);
        }

        $candidates = $this->pending->pendingFor($date);
        $vehicles = $this->fleet->availableFor($date);

        $departAt = $this->parseDepartAt($params->departAt);
        $origin = $this->origin->locate($params->originNodeId);

        $result = $this->backend->route($departAt, $origin, $candidates, $vehicles, $params);

        $existingByVehicle = $this->existingPlansByVehicle($date);

        foreach ($result->routes as $route) {
            $this->materializeRoute($route, $date, $existingByVehicle);
        }

        $run->complete(
            $result->trucksPlanned(),
            $result->stopsPlanned(),
            $result->unassignedCount(),
            $this->clock->now(),
        );
        $this->runs->save($run);
        $this->recordEvents->record($run->releaseEvents(), 'planning.auto_plan_run');
    }

    /**
     * Convierte la ruta resuelta de un camión en un plan propuesto: asigna los pedidos, fija el orden y la
     * ETA que trae la ruta, adjunta la geometría por calles y recomputa el plan de carga. Reusa el plan
     * existente del día para ese camión (idempotencia) y respeta los planes ya publicados (no los pisa).
     *
     * @param array<string, DeliveryPlan> $existingByVehicle
     */
    private function materializeRoute(PlannedRoute $route, PlanDate $date, array $existingByVehicle): void
    {
        $existing = $existingByVehicle[$route->vehicleId] ?? null;
        if ($existing !== null && $existing->status() === PlanStatus::Published) {
            // Plan ya publicado para ese camión y día: no se re-planifica encima.
            return;
        }

        $planId = $existing !== null ? $existing->id() : $this->plans->nextIdentity();

        $plan = DeliveryPlan::startDraft($planId, $date, $route->vehicleId);
        foreach ($route->candidates as $candidate) {
            $plan->assignShipment(
                PlannedStopId::generate(),
                $candidate->shipmentId,
                $candidate->orderReference,
                $candidate->customerName,
                $candidate->address,
            );
        }

        $plan->proposeRoute($route->orderedShipmentIds(), $route->etas);

        if ($route->geometry !== null) {
            $plan->attachRouteGeometry($route->geometry);
        }

        $plan->recomputeLoadPlan();

        $this->plans->save($plan);
        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }

    /**
     * Planes existentes del día indexados por vehículo, para reusar su id (idempotencia) y respetar los
     * publicados.
     *
     * @return array<string, DeliveryPlan>
     */
    private function existingPlansByVehicle(PlanDate $date): array
    {
        $byVehicle = [];
        foreach ($this->plans->findByDate($date) as $plan) {
            $byVehicle[$plan->vehicleId()] = $plan;
        }

        return $byVehicle;
    }

    private function parseDepartAt(string $departAt): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($departAt);
        } catch (Exception) {
            throw PlanningException::because("hora de salida inválida: {$departAt}");
        }
    }
}
