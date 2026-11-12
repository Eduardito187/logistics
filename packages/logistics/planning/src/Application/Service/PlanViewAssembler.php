<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use Logistics\Planning\Application\Contract\PlanCrewLabels;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Domain\Model\DeliveryPlan;

/**
 * Arma los PlanView de un lote de planes enriquecidos con la placa del camión y el nombre del chofer del
 * día. Read-side puro: dos lecturas EN LOTE por llamada (placas y choferes), sin importar cuántos planes
 * traiga el lote; nunca una consulta por plan (sin N+1).
 */
final readonly class PlanViewAssembler
{
    public function __construct(
        private PlanCrewLabels $labels,
    ) {}

    /**
     * @param  list<DeliveryPlan> $plans
     * @return list<PlanView>
     */
    public function assemble(array $plans): array
    {
        if ($plans === []) {
            return [];
        }

        $vehicleIds = [];
        $slots = [];
        foreach ($plans as $plan) {
            $vehicleIds[$plan->vehicleId()] = true;
            $key = $this->slotKey($plan);
            $slots[$key] = ['vehicle_id' => $plan->vehicleId(), 'plan_date' => $plan->date()->toString()];
        }

        $plates = $this->labels->platesFor(array_map('strval', array_keys($vehicleIds)));
        $drivers = $this->labels->driverNamesFor(array_values($slots));

        return array_map(
            fn (DeliveryPlan $plan): PlanView => PlanView::fromPlan(
                $plan,
                $plates[$plan->vehicleId()] ?? null,
                $drivers[$this->slotKey($plan)] ?? null,
            ),
            $plans,
        );
    }

    public function one(DeliveryPlan $plan): PlanView
    {
        return $this->assemble([$plan])[0];
    }

    private function slotKey(DeliveryPlan $plan): string
    {
        return $plan->vehicleId().PlanCrewLabels::SLOT_SEPARATOR.$plan->date()->toString();
    }
}
