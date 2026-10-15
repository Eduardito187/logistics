<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use DateTimeImmutable;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\TimeWindow;

/**
 * Servicio de aplicación REUTILIZABLE que re-secuencia la ruta de un plan y la persiste (R-05). Encapsula
 * el paso común a la re-optimización directa (ReoptimizeRoute) y a la reubicación de un envío
 * (PlaceReplannedShipment): arma la matriz real por carretera del depósito + las paradas en el orden
 * actual, re-secuencia con 2-opt/Or-opt (RouteSequencer::improve, que solo mejora o mantiene), recalcula
 * ETA y geometría, recomputa el plan de carga, guarda el plan y publica sus eventos al outbox.
 *
 * Vive en Application (orquesta contratos de dominio; nada del framework). El save y el append al outbox
 * corren en la misma transacción que abre el decorador transaccional del CommandBus.
 */
final readonly class RouteReoptimizer
{
    public function __construct(
        private PlanRepository $plans,
        private RoadMatrixProvider $matrixProvider,
        private RouteGeometryProvider $geometryProvider,
        private RouteSequencer $sequencer,
        private RecordDomainEvents $recordEvents,
    ) {}

    /**
     * Re-secuencia el plan dado y lo persiste. `coordinates` mapea cada id de envío del plan a su
     * [lat, lng, ?TimeWindow]; debe cubrir todas las paradas o se lanza excepción (no se puede rutear sin
     * coordenada). Con menos de dos paradas no hay nada que re-secuenciar pero igual se fija la ETA.
     *
     * @param array<string, array{0: float, 1: float, 2: ?TimeWindow}> $coordinates
     */
    public function reoptimize(DeliveryPlan $plan, GeoPoint $depot, array $coordinates, DateTimeImmutable $departAt, string $reason): void
    {
        $currentOrder = array_map(
            static fn (PlannedStop $stop): string => $stop->shipmentId(),
            $plan->stops(),
        );
        if ($currentOrder === []) {
            throw PlanningException::because('no se puede re-optimizar un plan sin paradas');
        }

        // Puntos de la matriz: índice 0 = depósito, 1..n = paradas EN EL ORDEN ACTUAL del plan.
        $points = [$depot];
        $windows = [];
        foreach ($currentOrder as $position => $shipmentId) {
            if (!isset($coordinates[$shipmentId])) {
                throw PlanningException::because("falta la coordenada del envío {$shipmentId} para re-optimizar");
            }
            [$lat, $lng, $window] = $coordinates[$shipmentId];
            $points[] = GeoPoint::of($lat, $lng);
            if ($window !== null) {
                $windows[$position + 1] = $window; // 0 es el depósito
            }
        }

        $matrix = $this->matrixProvider->matrixFor($points);

        // Orden actual como índices 1..n; improve solo aplica movimientos que BAJAN el costo.
        $currentIndices = array_values(range(1, count($currentOrder)));
        $newIndices = $this->sequencer->improve($matrix, $currentIndices, $windows);
        $arrivals = $this->sequencer->arrivalSeconds($matrix, $newIndices, $windows);

        $orderedShipmentIds = [];
        $etas = [];
        $geometryPoints = [$depot];
        foreach ($newIndices as $position => $stopIndex) {
            $shipmentId = $currentOrder[$stopIndex - 1];
            $minutes = (int) round($arrivals[$position] / 60);
            $orderedShipmentIds[] = $shipmentId;
            $etas[$shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
            $geometryPoints[] = $points[$stopIndex];
        }

        $plan->reoptimizeTo($orderedShipmentIds, $etas, $reason);

        // La geometría necesita al menos dos puntos (depósito + una parada); con una sola parada el
        // proveedor igual la arma (depósito → parada). Si el proveedor no responde, degrada a null.
        $geometry = $this->geometryProvider->geometryFor($geometryPoints);
        if ($geometry !== null) {
            $plan->attachRouteGeometry($geometry);
        }

        $plan->recomputeLoadPlan();

        $this->plans->save($plan);
        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }
}
