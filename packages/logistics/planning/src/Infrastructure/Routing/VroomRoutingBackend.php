<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Service\DepartmentRouteGrouping;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use RuntimeException;
use Throwable;

/**
 * Backend 'vroom': delega el VRP a un servidor VROOM (Vehicle Routing Open-source Optimization Machine) si
 * VROOM_URL está configurado. Si no lo está, o si el servidor falla, CAE al backend 'builtin'
 * (Clarke-Wright + 2-opt), de modo que la planificación nunca se queda sin motor.
 *
 * Resuelve POR GRUPO (departamento, y franja en modo slots) con los mismos criterios de particionado que el
 * builtin: así cada ruta devuelta por VROOM es de un solo departamento (invariante inamovible). La
 * restricción de capacidad (kg/m3) se traslada a VROOM como capacidad del vehículo y demanda de cada job.
 * La geometría por calles se calcula igual que en los demás backends y se persiste con el plan.
 */
final readonly class VroomRoutingBackend implements RoutingBackend
{
    /** Escala para pasar m3 (float) a enteros que VROOM entiende (litros), preservando precisión. */
    private const VOLUME_SCALE = 1000;

    public function __construct(
        private HttpClient $http,
        private RouteGeometryProvider $geometryProvider,
        private DepartmentRouteGrouping $grouping,
        private BuiltinRoutingBackend $fallback,
        private ?string $vroomUrl,
        private int $timeout,
    ) {}

    public function route(
        DateTimeImmutable $departAt,
        ?GeoPoint $origin,
        array $candidates,
        array $vehicles,
        AutoPlanParameters $params,
    ): RoutingResult {
        $url = $this->vroomUrl === null ? '' : trim($this->vroomUrl);
        if ($url === '') {
            // Sin servidor VROOM configurado: se usa el motor interno.
            return $this->fallback->route($departAt, $origin, $candidates, $vehicles, $params);
        }

        try {
            return $this->solveWithVroom($url, $departAt, $origin, $candidates, $vehicles, $params);
        } catch (Throwable) {
            // Cualquier fallo del servidor VROOM degrada al motor interno, nunca tumba la planificación.
            return $this->fallback->route($departAt, $origin, $candidates, $vehicles, $params);
        }
    }

    /**
     * @param list<DeliveryCandidate>   $candidates
     * @param list<VehicleCapacitySpec> $vehicles
     */
    private function solveWithVroom(
        string $url,
        DateTimeImmutable $departAt,
        ?GeoPoint $origin,
        array $candidates,
        array $vehicles,
        AutoPlanParameters $params,
    ): RoutingResult {
        $pool = array_values(array_filter($vehicles, static fn (VehicleCapacitySpec $v): bool => $v->hasCrew));
        $ratio = $params->usableCapacityRatio();

        /** @var list<PlannedRoute> $routes */
        $routes = [];
        /** @var list<DeliveryCandidate> $unassigned */
        $unassigned = [];

        foreach ($this->grouping->groupCandidates($candidates, $params->strategy) as $group) {
            $eligible = $this->grouping->eligibleVehicles($pool, $group[0]->departmentId);
            if ($eligible === []) {
                array_push($unassigned, ...$group);

                continue;
            }

            $depot = $this->grouping->resolveDepot($origin, $eligible, $group);

            [$groupRoutes, $groupUnassigned, $usedVehicleIds] = $this->solveGroup($url, $departAt, $depot, $group, $eligible, $ratio);

            array_push($routes, ...$groupRoutes);
            array_push($unassigned, ...$groupUnassigned);

            $used = array_fill_keys($usedVehicleIds, true);
            $pool = array_values(array_filter($pool, static fn (VehicleCapacitySpec $v): bool => !isset($used[$v->vehicleId])));
        }

        return new RoutingResult($routes, $unassigned);
    }

    /**
     * Resuelve un grupo con VROOM y traduce la solución a rutas de dominio.
     *
     * @param  list<DeliveryCandidate>                                                   $group
     * @param  list<VehicleCapacitySpec>                                                 $eligible
     * @return array{0: list<PlannedRoute>, 1: list<DeliveryCandidate>, 2: list<string>}
     */
    private function solveGroup(
        string $url,
        DateTimeImmutable $departAt,
        GeoPoint $depot,
        array $group,
        array $eligible,
        float $ratio,
    ): array {
        $vehiclePayload = [];
        /** @var array<int, VehicleCapacitySpec> $vehicleById */
        $vehicleById = [];
        foreach ($eligible as $index => $vehicle) {
            $vroomId = $index + 1;
            $vehicleById[$vroomId] = $vehicle;
            $vehiclePayload[] = [
                'id' => $vroomId,
                'start' => [$depot->longitude, $depot->latitude],
                'capacity' => [
                    (int) round($vehicle->capacityKg * $ratio),
                    (int) round($vehicle->capacityM3 * $ratio * self::VOLUME_SCALE),
                ],
            ];
        }

        $jobPayload = [];
        /** @var array<int, DeliveryCandidate> $candidateByJob */
        $candidateByJob = [];
        foreach ($group as $index => $candidate) {
            $jobId = $index + 1;
            $candidateByJob[$jobId] = $candidate;
            $jobPayload[] = [
                'id' => $jobId,
                'location' => [$candidate->longitude, $candidate->latitude],
                'delivery' => [
                    (int) round($candidate->weightKg),
                    (int) round($candidate->volumeM3 * self::VOLUME_SCALE),
                ],
            ];
        }

        $body = $this->callVroom($url, $vehiclePayload, $jobPayload);

        return $this->parseSolution($body, $departAt, $depot, $candidateByJob, $vehicleById);
    }

    /**
     * @param  list<array<string, mixed>> $vehicles
     * @param  list<array<string, mixed>> $jobs
     * @return array<string, mixed>
     */
    private function callVroom(string $url, array $vehicles, array $jobs): array
    {
        $response = $this->http->timeout($this->timeout)
            ->connectTimeout($this->timeout)
            ->acceptJson()
            ->post(rtrim($url, '/'), ['vehicles' => $vehicles, 'jobs' => $jobs]);

        if (!$response->ok()) {
            throw new RuntimeException('VROOM respondió con error HTTP');
        }

        /** @var mixed $body */
        $body = $response->json();
        if (!is_array($body) || ($body['code'] ?? null) !== 0) {
            throw new RuntimeException('VROOM respondió un código distinto de 0');
        }

        return $body;
    }

    /**
     * Traduce la respuesta de VROOM (routes + unassigned) a rutas de dominio con orden, ETA y geometría.
     *
     * @param  array<string, mixed>                                                      $body
     * @param  array<int, DeliveryCandidate>                                             $candidateByJob
     * @param  array<int, VehicleCapacitySpec>                                           $vehicleById
     * @return array{0: list<PlannedRoute>, 1: list<DeliveryCandidate>, 2: list<string>}
     */
    private function parseSolution(
        array $body,
        DateTimeImmutable $departAt,
        GeoPoint $depot,
        array $candidateByJob,
        array $vehicleById,
    ): array {
        /** @var list<PlannedRoute> $routes */
        $routes = [];
        /** @var list<string> $usedVehicleIds */
        $usedVehicleIds = [];
        /** @var array<int, true> $servedJobs */
        $servedJobs = [];

        $vroomRoutes = $body['routes'] ?? null;
        if (!is_array($vroomRoutes)) {
            throw new RuntimeException('VROOM no devolvió rutas');
        }

        foreach ($vroomRoutes as $vroomRoute) {
            if (!is_array($vroomRoute)) {
                continue;
            }
            $vehicleRef = $vroomRoute['vehicle'] ?? null;
            $vehicle = is_int($vehicleRef) ? ($vehicleById[$vehicleRef] ?? null) : null;
            $steps = $vroomRoute['steps'] ?? null;
            if ($vehicle === null || !is_array($steps)) {
                continue;
            }

            $ordered = [];
            $etas = [];
            $geometryPoints = [$depot];
            foreach ($steps as $step) {
                if (!is_array($step) || ($step['type'] ?? null) !== 'job') {
                    continue;
                }
                $jobId = $step['id'] ?? null;
                $candidate = is_int($jobId) ? ($candidateByJob[$jobId] ?? null) : null;
                if ($candidate === null) {
                    continue;
                }
                $arrival = $step['arrival'] ?? 0;
                $minutes = is_numeric($arrival) ? (int) round(((float) $arrival) / 60) : 0;
                $ordered[] = $candidate;
                $etas[$candidate->shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), max(0, $minutes));
                $geometryPoints[] = GeoPoint::of($candidate->latitude, $candidate->longitude);
                if (is_int($jobId)) {
                    $servedJobs[$jobId] = true;
                }
            }

            if ($ordered === []) {
                continue;
            }

            $geometry = $this->geometryProvider->geometryFor($geometryPoints);
            $routes[] = new PlannedRoute($vehicle->vehicleId, $vehicle->departmentId, $ordered, $etas, $geometry);
            $usedVehicleIds[] = $vehicle->vehicleId;
        }

        $unassigned = [];
        foreach ($candidateByJob as $jobId => $candidate) {
            if (!isset($servedJobs[$jobId])) {
                $unassigned[] = $candidate;
            }
        }

        return [$routes, $unassigned, $usedVehicleIds];
    }
}
