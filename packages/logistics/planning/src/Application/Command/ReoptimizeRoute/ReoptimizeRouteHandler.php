<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ReoptimizeRoute;

use DateTimeImmutable;
use Exception;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RoadMatrixProvider;
use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\PlannedStop;
use Logistics\Planning\Domain\Service\RouteSequencer;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\TimeWindow;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Re-optimiza la ruta de un plan (R-05): carga el plan, arma la matriz real por carretera de su depósito y
 * sus paradas EN EL ORDEN ACTUAL, re-secuencia con 2-opt/Or-opt (RouteSequencer::improve, que solo mejora
 * o mantiene), recalcula ETA y geometría, y persiste. Reutiliza el re-secuenciador; no rehace el día.
 *
 * El save del repositorio y el append al outbox corren en la misma transacción que abre el decorador
 * transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework. La matriz y la
 * geometría son I/O de Infrastructure DETRÁS de contratos de dominio, idempotentes y cacheados.
 *
 * @implements CommandHandler<ReoptimizeRoute>
 */
final readonly class ReoptimizeRouteHandler implements CommandHandler
{
    public function __construct(
        private PlanRepository $plans,
        private RoadMatrixProvider $matrixProvider,
        private RouteGeometryProvider $geometryProvider,
        private RouteSequencer $sequencer,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReoptimizeRoute) {
            throw PlanningException::because('ReoptimizeRouteHandler solo maneja ReoptimizeRoute');
        }

        $plan = $this->plans->findById(PlanId::fromString($command->planId))
            ?? throw PlanningException::because("no existe el plan {$command->planId}");

        $currentOrder = array_map(
            static fn (PlannedStop $stop): string => $stop->shipmentId(),
            $plan->stops(),
        );

        // Con menos de dos paradas no hay nada que re-secuenciar: la ruta ya es óptima trivialmente.
        if (count($currentOrder) < 2) {
            return;
        }

        $departAt = $this->parseDepartAt($command->departAt);
        $coordinates = $this->coordinatesByShipment($command);

        // Puntos de la matriz: índice 0 = depósito, 1..n = paradas EN EL ORDEN ACTUAL del plan.
        $depot = GeoPoint::of($command->depotLatitude, $command->depotLongitude);
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

        // Orden actual como índices 1..n; improve solo aplica movimientos que BAJAN el costo (mejora o mantiene).
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

        $plan->reoptimizeTo($orderedShipmentIds, $etas, $command->reason);

        $geometry = $this->geometryProvider->geometryFor($geometryPoints);
        if ($geometry !== null) {
            $plan->attachRouteGeometry($geometry);
        }

        $plan->recomputeLoadPlan();

        $this->plans->save($plan);
        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
    }

    /**
     * Mapa shipmentId => [lat, lng, ?TimeWindow] desde las paradas del comando. La ventana se convierte a
     * segundos desde la salida reutilizando la lógica validada de DeliveryCandidate.
     *
     * @return array<string, array{0: float, 1: float, 2: ?TimeWindow}>
     */
    private function coordinatesByShipment(ReoptimizeRoute $command): array
    {
        $map = [];
        foreach ($command->stops as $stop) {
            $window = DeliveryCandidate::of(
                shipmentId: $stop->shipmentId,
                latitude: $stop->latitude,
                longitude: $stop->longitude,
                weightKg: 0.0,
                volumeM3: 0.0,
                windowStart: $stop->windowStart,
                windowEnd: $stop->windowEnd,
            )->deliveryWindow($this->parseDepartAt($command->departAt));

            $map[$stop->shipmentId] = [$stop->latitude, $stop->longitude, $window];
        }

        return $map;
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
