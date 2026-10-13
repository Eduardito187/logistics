<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

use DateTimeImmutable;
use Exception;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Genera la propuesta automática: arma el borrador con los envíos dados, corre el motor de rutas para
 * resolver orden y ETA, recalcula el plan de carga y persiste el plan ya propuesto.
 *
 * Deja el id del plan generado en lastPlanId() para que el llamador lo use (p. ej. para publicarlo).
 *
 * @implements CommandHandler<GeneratePlanProposal>
 */
final class GeneratePlanProposalHandler implements CommandHandler
{
    private ?PlanId $lastPlanId = null;

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly RouteOptimizer $optimizer,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof GeneratePlanProposal) {
            throw PlanningException::because('GeneratePlanProposalHandler solo maneja GeneratePlanProposal');
        }
        if ($command->stops === []) {
            throw PlanningException::because('la propuesta necesita al menos un envío');
        }

        $departAt = $this->parseDepartAt($command->departAt);

        $routeStops = array_map(
            static fn (ProposalStopInput $input): RouteStop => RouteStop::of(
                $input->shipmentId,
                $input->latitude,
                $input->longitude,
            ),
            $command->stops,
        );

        $optimized = $this->optimizer->optimize($departAt, $routeStops);

        $plan = DeliveryPlan::startDraft(
            $this->plans->nextIdentity(),
            PlanDate::fromString($command->planDate),
            $command->vehicleId,
        );
        foreach ($command->stops as $input) {
            $plan->assignShipment(PlannedStopId::generate(), $input->shipmentId);
        }

        $orderedShipmentIds = [];
        /** @var array<string, Eta> $etas */
        $etas = [];
        foreach ($optimized as $stop) {
            $orderedShipmentIds[] = $stop->shipmentId;
            $etas[$stop->shipmentId] = $stop->eta;
        }

        $plan->proposeRoute($orderedShipmentIds, $etas);
        $plan->recomputeLoadPlan();

        $this->plans->save($plan);
        $this->lastPlanId = $plan->id();

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $plan->releaseEvents();
    }

    /**
     * Id del plan generado tras el último handle().
     */
    public function lastPlanId(): PlanId
    {
        if ($this->lastPlanId === null) {
            throw PlanningException::because('todavía no se generó ningún plan en este handler');
        }

        return $this->lastPlanId;
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
