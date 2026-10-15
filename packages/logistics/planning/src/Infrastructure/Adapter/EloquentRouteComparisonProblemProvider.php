<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteComparisonProblemProvider;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\RouteComparisonProblem;
use Logistics\Planning\Domain\ValueObject\ShipmentRouteRequirement;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Reconstruye el problema de ruteo de un plan (R-06) para comparar backends: lee las coordenadas y la
 * carga de los envíos del plan (contexto Envíos), la capacidad/perfil del camión (contexto Flota) y resuelve
 * el CD de origen. Vive en Infrastructure (read-only de otros contextos); traduce al VO de dominio.
 *
 * El camión se arma como FLEXIBLE (sin departamento) para que sea elegible por el único grupo de paradas,
 * y como tripulado para que entre al pool del backend. Se preservan los requisitos de ruteo (R-04) de cada
 * envío y las restricciones del camión, de modo que la comparación respete las mismas reglas que el plan.
 *
 * Devuelve null si el plan no existe o no quedó ningún envío con coordenadas ni un camión con capacidad.
 */
final readonly class EloquentRouteComparisonProblemProvider implements RouteComparisonProblemProvider
{
    public function __construct(
        private PlanRepository $plans,
        private PlanningOriginLocator $originLocator,
        private Config $config,
    ) {}

    public function forPlan(PlanId $planId): ?RouteComparisonProblem
    {
        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            return null;
        }

        $shipmentIds = array_values(array_unique(array_map(
            static fn (PlannedStop $stop): string => $stop->shipmentId(),
            $plan->stops(),
        )));

        $vehicle = $this->vehicleSpecFor($plan->vehicleId());
        if ($vehicle === null) {
            return null;
        }

        $candidates = $this->candidatesFor($shipmentIds);
        if ($candidates === []) {
            return null;
        }

        $origin = $this->originLocator->locate($this->stringConfig('origin_node_id'));

        return new RouteComparisonProblem($plan->date(), $origin, $candidates, $vehicle);
    }

    /**
     * Arma los candidatos desde los envíos del plan: coordenadas, carga estimada (bultos × defaults) y
     * requisitos de ruteo (R-04). Descarta los envíos sin coordenadas numéricas.
     *
     * @param  list<string>            $shipmentIds
     * @return list<DeliveryCandidate>
     */
    private function candidatesFor(array $shipmentIds): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        $defaultWeight = $this->floatConfig('default_weight_kg_per_package', 10.0);
        $defaultVolume = $this->floatConfig('default_volume_m3_per_package', 0.05);
        $requirements = $this->shipmentRequirements($shipmentIds);

        try {
            $records = ShipmentRecord::query()
                ->whereIn('id', $shipmentIds)
                ->withSum('lines', 'quantity')
                ->orderBy('id')
                ->get();
        } catch (Throwable) {
            return [];
        }

        $candidates = [];
        foreach ($records as $record) {
            if (!is_numeric($record->lat) || !is_numeric($record->lng)) {
                continue;
            }

            $packagesRaw = $record->getAttribute('lines_sum_quantity');
            $packages = is_numeric($packagesRaw) ? max(1, (int) $packagesRaw) : 1;
            $shipmentId = (string) $record->id;

            $candidates[] = DeliveryCandidate::of(
                shipmentId: $shipmentId,
                latitude: (float) $record->lat,
                longitude: (float) $record->lng,
                weightKg: $packages * $defaultWeight,
                volumeM3: $packages * $defaultVolume,
                // Sin departamento: el camión de la comparación es flexible y toma todas las paradas.
                departmentId: null,
                orderReference: $record->external_id,
                customerName: $record->customer_name,
                address: $record->address_text,
                requirement: $requirements[$shipmentId] ?? null,
            );
        }

        return $candidates;
    }

    /**
     * Arma el camión del plan como vehículo FLEXIBLE y tripulado, con su capacidad, nodo base y perfil de
     * restricciones (R-04). Devuelve null si el camión no existe o no tiene capacidad válida.
     */
    private function vehicleSpecFor(string $vehicleId): ?VehicleCapacitySpec
    {
        try {
            $vehicle = VehicleModel::query()->with('homeBaseNode')->find($vehicleId);
        } catch (Throwable) {
            return null;
        }

        if ($vehicle === null || !is_numeric($vehicle->capacity_kg) || !is_numeric($vehicle->capacity_m3)) {
            return null;
        }
        if ((float) $vehicle->capacity_kg <= 0.0 || (float) $vehicle->capacity_m3 <= 0.0) {
            return null;
        }

        $node = $vehicle->homeBaseNode;
        $lat = $node !== null && is_numeric($node->lat) ? (float) $node->lat : null;
        $lng = $node !== null && is_numeric($node->lng) ? (float) $node->lng : null;

        return VehicleCapacitySpec::of(
            vehicleId: (string) $vehicle->id,
            capacityKg: (float) $vehicle->capacity_kg,
            capacityM3: (float) $vehicle->capacity_m3,
            homeBaseNodeId: (string) $vehicle->home_base_node_id,
            hasCrew: true,
            departmentId: null,
            homeBaseLatitude: $lat,
            homeBaseLongitude: $lng,
            profile: $this->restrictionProfile($vehicleId),
        );
    }

    /**
     * Perfil de restricciones (R-04) del camión desde el read-side propio de Planificación. Degrada a "sin
     * restricciones" si la tabla no existe o no hay fila (comportamiento previo).
     */
    private function restrictionProfile(string $vehicleId): VehicleRouteProfile
    {
        try {
            $row = DB::table('planning_vehicle_restrictions')->where('vehicle_id', $vehicleId)->first();
        } catch (Throwable) {
            return VehicleRouteProfile::unrestricted();
        }

        if ($row === null) {
            return VehicleRouteProfile::unrestricted();
        }

        $data = (array) $row;

        return VehicleRouteProfile::fromArray([
            'allowed_load_types' => $this->decodeJson($data['allowed_load_types'] ?? null),
            'allowed_zones' => $this->decodeJson($data['allowed_zones'] ?? null),
            'forbidden_zones' => $this->decodeJson($data['forbidden_zones'] ?? null),
            'max_height_m' => $data['max_height_m'] ?? null,
            'max_weight_kg' => $data['max_weight_kg'] ?? null,
        ]);
    }

    /**
     * Requisitos de ruteo (R-04) por envío, leídos en lote. Degrada a vacío si la tabla no existe.
     *
     * @param  list<string>                            $shipmentIds
     * @return array<string, ShipmentRouteRequirement>
     */
    private function shipmentRequirements(array $shipmentIds): array
    {
        try {
            $rows = DB::table('planning_shipment_requirements')->whereIn('shipment_id', $shipmentIds)->get();
        } catch (Throwable) {
            return [];
        }

        $requirements = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $shipmentId = isset($data['shipment_id']) ? (string) $data['shipment_id'] : '';
            if ($shipmentId === '') {
                continue;
            }

            $requirements[$shipmentId] = ShipmentRouteRequirement::fromArray([
                'load_type' => $data['load_type'] ?? null,
                'zone' => $data['zone'] ?? null,
                'height_m' => $data['height_m'] ?? null,
                'weight_kg' => $data['weight_kg'] ?? null,
            ]);
        }

        return $requirements;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return null;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
