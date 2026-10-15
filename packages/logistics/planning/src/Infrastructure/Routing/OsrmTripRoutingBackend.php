<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Routing;

use DateTimeImmutable;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Contract\RoutingBackend;
use Logistics\Planning\Domain\Service\DailyPlanComposer;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlannedRoute;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Domain\ValueObject\TruckLoad;

/**
 * Backend 'osrm_trip': conserva el comportamiento ANTERIOR del auto-planificador —empaque greedy
 * (DailyPlanComposer, first-fit-decreasing por volumen) + orden por camión con OSRM /trip (RouteOptimizer)—
 * y le agrega la geometría server-side por ruta. Se mantiene como opción seleccionable por configuración
 * para poder comparar contra el 'builtin' (Clarke-Wright + 2-opt) sin perder el motor previo.
 */
final readonly class OsrmTripRoutingBackend implements RoutingBackend
{
    public function __construct(
        private DailyPlanComposer $composer,
        private RouteOptimizer $optimizer,
        private RouteGeometryProvider $geometryProvider,
    ) {}

    public function route(
        DateTimeImmutable $departAt,
        ?GeoPoint $origin,
        array $candidates,
        array $vehicles,
        AutoPlanParameters $params,
    ): RoutingResult {
        $composition = $this->composer->compose($candidates, $vehicles, $params);

        $routes = [];
        foreach ($composition->trucks as $truck) {
            $routes[] = $this->routeTruck($truck, $departAt, $origin);
        }

        return new RoutingResult($routes, $composition->unassigned);
    }

    private function routeTruck(TruckLoad $truck, DateTimeImmutable $departAt, ?GeoPoint $origin): PlannedRoute
    {
        $routeStops = array_map(
            static fn (DeliveryCandidate $c): RouteStop => RouteStop::of($c->shipmentId, $c->latitude, $c->longitude),
            $truck->candidates,
        );

        $optimized = $this->optimizer->optimize($departAt, $routeStops);

        $byShipment = [];
        foreach ($truck->candidates as $candidate) {
            $byShipment[$candidate->shipmentId] = $candidate;
        }

        $ordered = [];
        $etas = [];
        $geometryPoints = $origin !== null ? [$origin] : [];
        foreach ($optimized as $stop) {
            $candidate = $byShipment[$stop->shipmentId] ?? null;
            if ($candidate === null) {
                continue;
            }
            $ordered[] = $candidate;
            $etas[$candidate->shipmentId] = $stop->eta;
            $geometryPoints[] = GeoPoint::of($candidate->latitude, $candidate->longitude);
        }

        $geometry = count($geometryPoints) >= 2 ? $this->geometryProvider->geometryFor($geometryPoints) : null;

        return new PlannedRoute($truck->vehicleId, $truck->departmentId, $ordered, $etas, $geometry);
    }
}
