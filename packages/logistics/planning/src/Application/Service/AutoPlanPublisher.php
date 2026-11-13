<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\ReadModel\AutoPublishResult;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteLoadProfileProvider;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\Service\PublishGate;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\PublishDecision;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Orquestación de la AUTO-PUBLICACIÓN diaria (Fase 3.2): toma los planes PROPUESTOS del día, evalúa cada
 * uno con la PublishGate (ocupación, aptitud del vehículo) y publica los que pasan; los que no, quedan
 * propuestos y se reportan como RETENIDOS con sus motivos.
 *
 * Fan-out controlado: cada plan se publica con su PROPIO comando PublishPlan por el CommandBus, de modo
 * que cada publicación corre en su propia transacción (el decorador transaccional del bus) y emite su
 * evento planning.plan_published al outbox. Así un plan que falle no frena a los demás ni abre una
 * transacción gigante.
 *
 * Idempotente por diseño: solo mira planes en estado PROPOSED; un plan ya PUBLICADO ni se evalúa ni se
 * re-publica (DeliveryPlan::publish exige Proposed). Re-ejecutar la corrida no duplica nada.
 */
final readonly class AutoPlanPublisher
{
    public function __construct(
        private PlanRepository $plans,
        private FleetAvailabilityProvider $fleet,
        private RouteLoadProfileProvider $loadProfiles,
        private PublishGate $gate,
        private CommandBus $commandBus,
    ) {}

    /**
     * Evalúa y publica los planes propuestos del día. Devuelve el resumen de la corrida.
     */
    public function run(PlanDate $date, PublishThresholds $thresholds): AutoPublishResult
    {
        $availableVehicleIds = $this->availableVehicleIds($date);

        $evaluated = 0;
        $published = 0;
        $held = [];

        foreach ($this->plans->findByDate($date) as $plan) {
            if ($plan->status() !== PlanStatus::Proposed) {
                continue;
            }

            $evaluated++;

            $decision = $this->evaluate($plan, $availableVehicleIds, $thresholds);

            if ($decision->publishable) {
                $this->commandBus->dispatch(new PublishPlan($plan->id()->toString()));
                $published++;

                continue;
            }

            $held[] = [
                'plan_id' => $plan->id()->toString(),
                'vehicle_id' => $plan->vehicleId(),
                'reasons' => $decision->reasons,
            ];
        }

        return new AutoPublishResult($evaluated, $published, $held);
    }

    /**
     * Evalúa un plan: resuelve su ocupación (carga contra capacidad del camión) y su aptitud, y delega la
     * decisión en la PublishGate (dominio puro).
     *
     * @param array<string, true> $availableVehicleIds
     */
    private function evaluate(
        DeliveryPlan $plan,
        array $availableVehicleIds,
        PublishThresholds $thresholds,
    ): PublishDecision {
        $shipmentIds = array_map(
            static fn (PlannedStop $stop): string => $stop->shipmentId(),
            $plan->stops(),
        );

        $load = $this->loadProfiles->loadFor(array_values($shipmentIds));
        $capacity = $this->loadProfiles->capacityFor($plan->vehicleId());

        $occupancyKg = $this->ratio($load['kg'], $capacity !== null ? $capacity['kg'] : null);
        $occupancyM3 = $this->ratio($load['m3'], $capacity !== null ? $capacity['m3'] : null);

        return $this->gate->evaluate(
            $plan->status(),
            count($plan->stops()),
            $occupancyKg,
            $occupancyM3,
            isset($availableVehicleIds[$plan->vehicleId()]),
            $thresholds,
        );
    }

    /**
     * Conjunto (como mapa id => true, para búsqueda O(1)) de los vehículos disponibles/aptos para la fecha.
     *
     * @return array<string, true>
     */
    private function availableVehicleIds(PlanDate $date): array
    {
        $ids = [];
        foreach ($this->fleet->availableFor($date) as $spec) {
            $ids[$spec->vehicleId] = true;
        }

        return $ids;
    }

    /**
     * Ratio carga/capacidad, o null si la capacidad no se conoce o no es positiva.
     */
    private function ratio(float $load, ?float $capacity): ?float
    {
        if ($capacity === null || $capacity <= 0.0) {
            return null;
        }

        return $load / $capacity;
    }
}
