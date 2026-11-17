<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use Logistics\Planning\Application\Contract\PlanCrewLabels;
use Logistics\Planning\Application\Contract\PlanExecutionStatusReader;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\Contract\TripVehicleReader;
use Logistics\Planning\Application\ReadModel\PlanView;
use Logistics\Planning\Domain\Model\DeliveryPlan;

/**
 * Arma los PlanView de un lote de planes enriquecidos con sus datos humanos: la placa del camión, el
 * chofer del día, el nodo del que sale el camión y, por parada, los datos legibles del envío (pedido,
 * cliente, dirección corta, modalidad, unidades y hora elegida por el cliente).
 *
 * Read-side puro y EN LOTE: cada dato se lee una vez por llamada para TODOS los planes y TODAS sus paradas
 * (placas, choferes, camiones con su base y envíos), sin importar cuántos planes traiga el lote; nunca una
 * consulta por plan ni por parada (sin N+1).
 */
final readonly class PlanViewAssembler
{
    public function __construct(
        private PlanCrewLabels $labels,
        private ShipmentPlanningFactsReader $shipments,
        private TripVehicleReader $vehicles,
        private TripBuilderSettings $settings,
        private PlanExecutionStatusReader $executionStatuses,
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
        $shipmentIds = [];
        foreach ($plans as $plan) {
            $vehicleIds[$plan->vehicleId()] = true;
            $key = $this->slotKey($plan);
            $slots[$key] = ['vehicle_id' => $plan->vehicleId(), 'plan_date' => $plan->date()->toString()];
            foreach ($plan->stops() as $stop) {
                $shipmentIds[$stop->shipmentId()] = true;
            }
        }

        $vehicleIds = array_map('strval', array_keys($vehicleIds));

        $plates = $this->labels->platesFor($vehicleIds);
        $drivers = $this->labels->driverNamesFor(array_values($slots));
        $vehicles = $this->vehicles->vehiclesFor($vehicleIds);
        $facts = $shipmentIds === [] ? [] : $this->shipments->factsFor(array_map('strval', array_keys($shipmentIds)));
        // Estado de EJECUCIÓN de la ruta del día de cada plan (cross-context, en lote): «en curso»/«cerrada».
        $executionStatuses = $this->executionStatuses->statusesFor(array_values($slots));
        $timezone = $this->settings->timeZone();

        return array_map(
            fn (DeliveryPlan $plan): PlanView => PlanView::fromPlan(
                $plan,
                $plates[$plan->vehicleId()] ?? null,
                $drivers[$this->slotKey($plan)] ?? null,
                $facts,
                ($vehicles[strtolower($plan->vehicleId())] ?? null)?->originNode(),
                $timezone,
                $executionStatuses[$plan->vehicleId().'|'.$plan->date()->toString()] ?? null,
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
