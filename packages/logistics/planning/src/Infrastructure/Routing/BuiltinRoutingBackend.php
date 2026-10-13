<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Service\ClarkeWrightSolver;
use Logistics\Planning\Domain\Service\DepartmentRouteGrouping;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\DistanceMatrix;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Backend de ruteo POR DEFECTO ('builtin'): Clarke-Wright (savings) para asignar paradas a camiones +
 * 2-opt/Or-opt para el orden de cada ruta, todo sobre la MATRIZ real por carretera. Reemplaza al empaque
 * greedy y al orden por cercanía simple.
 *
 * Orquesta (I/O en el worker, nunca en un request): parte los pedidos por departamento (y franja en modo
 * slots), respeta la invariante "un camión = un departamento" y la capacidad (kg/m3), pide la matriz real
 * por grupo (con caché y degradación), resuelve el VRP con Clarke-Wright, afina el orden con RouteSequencer
 * y calcula la geometría de cada ruta por calles para persistirla con el plan.
 *
 * La geometría y la matriz son I/O de Infrastructure; los algoritmos (Clarke-Wright, 2-opt) son dominio
 * puro. Determinista: con las mismas entradas produce el mismo reparto y el mismo orden.
 */
final readonly class BuiltinRoutingBackend implements RoutingBackend
{
    public function __construct(
        private RoadMatrixProvider $matrixProvider,
        private RouteGeometryProvider $geometryProvider,
        private ClarkeWrightSolver $solver,
        private RouteSequencer $sequencer,
        private DepartmentRouteGrouping $grouping,
    ) {}

    public function route(
        DateTimeImmutable $departAt,
        ?GeoPoint $origin,
        array $candidates,
        array $vehicles,
        AutoPlanParameters $params,
    ): RoutingResult {
        // Solo camiones con tripulación completa pueden salir a ruta (como el empaque anterior).
        $pool = array_values(array_filter($vehicles, static fn (VehicleCapacitySpec $v): bool => $v->hasCrew));
        $ratio = $params->usableCapacityRatio();

        /** @var list<PlannedRoute> $routes */
        $routes = [];
        /** @var list<DeliveryCandidate> $unassigned */
        $unassigned = [];

        foreach ($this->grouping->groupCandidates($candidates, $params->strategy) as $group) {
            $departmentId = $group[0]->departmentId;
            $eligible = $this->grouping->eligibleVehicles($pool, $departmentId);

            if ($eligible === []) {
                array_push($unassigned, ...$group);

                continue;
            }

            $depot = $this->grouping->resolveDepot($origin, $eligible, $group);
            $points = $this->grouping->pointsFor($depot, $group);
            $matrix = $this->matrixProvider->matrixFor($points);

            [$assignedRoutes, $groupUnassigned] = $this->assignGroup($group, $eligible, $matrix, $ratio, $departAt, $depot, $pool);

            array_push($routes, ...$assignedRoutes);
            array_push($unassigned, ...$groupUnassigned);
        }

        return new RoutingResult($routes, $unassigned);
    }

    /**
     * Resuelve el VRP de un grupo con Clarke-Wright, asigna cada ruta a un camión elegible que la soporte
     * y afina el orden, la ETA y la geometría de las asignadas.
     *
     * @param  list<DeliveryCandidate>                                  $group
     * @param  list<VehicleCapacitySpec>                                $eligible
     * @param  list<VehicleCapacitySpec>                                $pool     referencia al pool compartido, del que se quitan los usados
     * @return array{0: list<PlannedRoute>, 1: list<DeliveryCandidate>}
     */
    private function assignGroup(
        array $group,
        array $eligible,
        DistanceMatrix $matrix,
        float $ratio,
        DateTimeImmutable $departAt,
        GeoPoint $depot,
        array &$pool,
    ): array {
        $demandsKg = [];
        $demandsM3 = [];
        foreach ($group as $index => $candidate) {
            $stopIndex = $index + 1; // 0 es el depósito
            $demandsKg[$stopIndex] = $candidate->weightKg;
            $demandsM3[$stopIndex] = $candidate->volumeM3;
        }

        // Tope de capacidad del savings: el camión elegible más grande (ninguna ruta debe superarlo).
        $maxKg = 0.0;
        $maxM3 = 0.0;
        foreach ($eligible as $vehicle) {
            $maxKg = max($maxKg, $vehicle->capacityKg * $ratio);
            $maxM3 = max($maxM3, $vehicle->capacityM3 * $ratio);
        }

        $cwRoutes = $this->solver->solve($matrix, $demandsKg, $demandsM3, $maxKg, $maxM3);

        // Rutas con su carga, ordenadas de mayor a menor (para asignar primero a los camiones grandes).
        $loaded = [];
        foreach ($cwRoutes as $stops) {
            $kg = 0.0;
            $m3 = 0.0;
            foreach ($stops as $stopIndex) {
                $kg += $demandsKg[$stopIndex];
                $m3 += $demandsM3[$stopIndex];
            }
            $loaded[] = ['stops' => $stops, 'kg' => $kg, 'm3' => $m3];
        }
        usort($loaded, static fn (array $a, array $b): int => [$b['kg'], $b['m3'], $a['stops'][0]] <=> [$a['kg'], $a['m3'], $b['stops'][0]]);

        // Camiones elegibles por capacidad utilizable descendente (desempate estable por id).
        $vehiclesByCapacity = $eligible;
        usort($vehiclesByCapacity, static fn (VehicleCapacitySpec $a, VehicleCapacitySpec $b): int => [$b->capacityKg, $b->capacityM3, $a->vehicleId] <=> [$a->capacityKg, $a->capacityM3, $b->vehicleId]);

        /** @var array<string, true> $used */
        $used = [];
        /** @var list<PlannedRoute> $routes */
        $routes = [];
        /** @var list<DeliveryCandidate> $unassigned */
        $unassigned = [];

        foreach ($loaded as $route) {
            $vehicle = $this->firstFittingVehicle($vehiclesByCapacity, $used, $route['kg'], $route['m3'], $ratio);
            if ($vehicle === null) {
                foreach ($route['stops'] as $stopIndex) {
                    $unassigned[] = $group[$stopIndex - 1];
                }

                continue;
            }

            $used[$vehicle->vehicleId] = true;
            $routes[] = $this->buildRoute($vehicle, $route['stops'], $group, $matrix, $departAt, $depot);
        }

        // Quitar del pool compartido los camiones ya usados (los flexibles no se reparten dos veces).
        $pool = array_values(array_filter($pool, static fn (VehicleCapacitySpec $v): bool => !isset($used[$v->vehicleId])));

        return [$routes, $unassigned];
    }

    /**
     * Construye la ruta planificada de un camión: afina el orden con 2-opt/Or-opt, fija la ETA acumulada y
     * calcula la geometría por calles.
     *
     * @param list<int>               $stops índices de parada (1..n) asignados al camión
     * @param list<DeliveryCandidate> $group
     */
    private function buildRoute(
        VehicleCapacitySpec $vehicle,
        array $stops,
        array $group,
        DistanceMatrix $matrix,
        DateTimeImmutable $departAt,
        GeoPoint $depot,
    ): PlannedRoute {
        $ordered = $this->sequencer->sequence($matrix, $stops);

        $candidates = [];
        $etas = [];
        $geometryPoints = [$depot];
        $cumulativeSeconds = 0.0;
        $previous = 0;

        foreach ($ordered as $stopIndex) {
            $candidate = $group[$stopIndex - 1];
            $cumulativeSeconds += $matrix->durationSeconds($previous, $stopIndex);
            $minutes = (int) round($cumulativeSeconds / 60);
            $candidates[] = $candidate;
            $etas[$candidate->shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
            $geometryPoints[] = GeoPoint::of($candidate->latitude, $candidate->longitude);
            $previous = $stopIndex;
        }

        $geometry = $this->geometryProvider->geometryFor($geometryPoints);

        return new PlannedRoute($vehicle->vehicleId, $vehicle->departmentId, $candidates, $etas, $geometry);
    }

    /**
     * Primer camión aún sin usar cuya capacidad utilizable soporte la carga de la ruta.
     *
     * @param list<VehicleCapacitySpec> $vehicles
     * @param array<string, true>       $used
     */
    private function firstFittingVehicle(array $vehicles, array $used, float $kg, float $m3, float $ratio): ?VehicleCapacitySpec
    {
        foreach ($vehicles as $vehicle) {
            if (isset($used[$vehicle->vehicleId])) {
                continue;
            }
            if ($vehicle->capacityKg * $ratio + 1e-9 >= $kg && $vehicle->capacityM3 * $ratio + 1e-9 >= $m3) {
                return $vehicle;
            }
        }

        return null;
    }
}
