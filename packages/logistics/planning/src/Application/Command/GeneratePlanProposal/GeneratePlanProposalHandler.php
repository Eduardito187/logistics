<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

use DateTimeImmutable;
use Exception;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\Contract\TripVehicleReader;
use Logistics\Planning\Application\Policy\ClientStopLocation;
use Logistics\Planning\Application\Policy\RouteDepartmentPolicy;
use Logistics\Planning\Application\Service\CustomerTimeWindow;
use Logistics\Planning\Application\Service\PlanGeometryRefresher;
use Logistics\Planning\Application\Service\TripBuilderSettings;
use Logistics\Planning\Application\Service\TripRouteCalculator;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteOptimizer;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Genera la propuesta: arma el borrador con los envíos dados, resuelve orden y ETA, recalcula el plan de
 * carga y persiste el plan ya propuesto.
 *
 * Lo que la petición no trae de cada envío (coordenadas, referencia del pedido, cliente, dirección) se
 * completa con los datos del envío, leídos EN LOTE por contrato. Un envío sin coordenadas en la petición y
 * sin ubicación propia corta la propuesta con un motivo legible (regla de negocio → 422).
 *
 * Orden y ETA:
 *  - por defecto, el motor de rutas (RouteOptimizer) reordena, con la primera parada como punto de salida;
 *  - con `keepOrder`, se respeta el orden recibido y la ETA se calcula saliendo de la base del camión, con
 *    la espera de la hora elegida por el cliente y el tiempo de servicio (el mismo cálculo del orden
 *    sugerido del asistente de viajes).
 *
 * Deja el id del plan generado en lastPlanId() para que el llamador lo use (p. ej. para publicarlo).
 *
 * @phpstan-type ResolvedStop array{shipment_id: string, latitude: float, longitude: float, order_reference: string|null, customer_name: string|null, address: string|null, client: bool, window: CustomerTimeWindow|null}
 *
 * @implements CommandHandler<GeneratePlanProposal>
 */
final class GeneratePlanProposalHandler implements CommandHandler
{
    private ?PlanId $lastPlanId = null;

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly RouteOptimizer $optimizer,
        private readonly RecordDomainEvents $recordEvents,
        private readonly RouteDepartmentPolicy $geography,
        private readonly ShipmentPlanningFactsReader $shipments,
        private readonly TripVehicleReader $vehicles,
        private readonly TripRouteCalculator $routes,
        private readonly TripBuilderSettings $settings,
        private readonly ?PlanGeometryRefresher $geometry = null,
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
        $stops = $this->resolveStops($command);

        // Invariante geográfica: todas las paradas de CLIENTE del camión deben ser del mismo
        // departamento (mismo municipio o intermunicipales dentro de él). Las paradas sin cliente
        // (traspasos CD→CD) quedan exentas: solo entran a la política las que tienen cliente.
        $clientStops = [];
        foreach ($stops as $stop) {
            if ($stop['client']) {
                $clientStops[] = ClientStopLocation::of($stop['shipment_id'], $stop['latitude'], $stop['longitude']);
            }
        }
        $this->geography->guard($clientStops);

        [$orderedShipmentIds, $etas] = $command->keepOrder
            ? $this->etasInReceivedOrder($command->vehicleId, $departAt, $stops)
            : $this->optimizedOrder($departAt, $stops);

        $plan = DeliveryPlan::startDraft(
            $this->plans->nextIdentity(),
            PlanDate::fromString($command->planDate),
            $command->vehicleId,
        );
        foreach ($stops as $stop) {
            $plan->assignShipment(
                PlannedStopId::generate(),
                $stop['shipment_id'],
                $stop['order_reference'],
                $stop['customer_name'],
                $stop['address'],
            );
        }

        $plan->proposeRoute($orderedShipmentIds, $etas);
        $plan->recomputeLoadPlan();

        // La propuesta nace con orden definitivo: se le calcula la geometría (degrada sin romper).
        $this->geometry?->refresh($plan);

        $this->plans->save($plan);
        $this->lastPlanId = $plan->id();

        $this->recordEvents->record($plan->releaseEvents(), 'planning.plan');
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

    /**
     * Completa cada parada con los datos del envío que la petición no trajo. Solo se consulta por los
     * envíos a los que les falta algo, y en una sola lectura en lote.
     *
     * @return list<ResolvedStop>
     */
    private function resolveStops(GeneratePlanProposal $command): array
    {
        $incomplete = [];
        foreach ($command->stops as $input) {
            $withoutSnapshot = $input->orderReference === null && $input->customerName === null && $input->address === null;
            if ($input->latitude === null || $input->longitude === null || $withoutSnapshot) {
                $incomplete[] = $input->shipmentId;
            }
        }

        $facts = $incomplete === [] ? [] : $this->shipments->factsFor($incomplete);
        $timezone = $this->settings->timeZone();

        $stops = [];
        foreach ($command->stops as $input) {
            $fact = $facts[strtolower(trim($input->shipmentId))] ?? null;

            $latitude = $input->latitude;
            $longitude = $input->longitude;
            if ($latitude === null || $longitude === null) {
                $latitude = $fact?->latitude;
                $longitude = $fact?->longitude;
            }
            if ($latitude === null || $longitude === null) {
                throw PlanningException::because(
                    $fact === null
                        ? 'Uno de los pedidos no existe o no tiene ubicación en el mapa: no se puede sumar al viaje.'
                        : ucfirst($fact->label()).' no tiene ubicación en el mapa: corrija su dirección antes de sumarlo al viaje.',
                );
            }

            $stops[] = [
                'shipment_id' => $input->shipmentId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'order_reference' => $input->orderReference ?? $fact?->orderReference,
                'customer_name' => $input->customerName ?? $fact?->customerName,
                'address' => $input->address ?? $fact?->address,
                // Parada de cliente: la que trae cliente en la petición o la que lo tiene en el envío y no
                // es un traspaso interno (exento de la regla de un solo departamento).
                'client' => $input->customerName !== null
                    || ($fact !== null && $fact->customerName !== null && !$fact->isTransfer()),
                'window' => $fact === null
                    ? null
                    : CustomerTimeWindow::on($command->planDate, $fact->windowStart, $fact->windowEnd, $timezone),
            ];
        }

        return $stops;
    }

    /**
     * Orden y ETA resueltos por el motor de rutas (comportamiento histórico).
     *
     * @param  list<ResolvedStop>                            $stops
     * @return array{0: list<string>, 1: array<string, Eta>}
     */
    private function optimizedOrder(DateTimeImmutable $departAt, array $stops): array
    {
        $routeStops = array_map(
            static fn (array $stop): RouteStop => RouteStop::of($stop['shipment_id'], $stop['latitude'], $stop['longitude']),
            $stops,
        );

        $orderedShipmentIds = [];
        $etas = [];
        foreach ($this->optimizer->optimize($departAt, $routeStops) as $optimized) {
            $orderedShipmentIds[] = $optimized->shipmentId;
            $etas[$optimized->shipmentId] = $optimized->eta;
        }

        return [$orderedShipmentIds, $etas];
    }

    /**
     * Orden RECIBIDO tal cual, con la ETA de cada parada saliendo de la base del camión (o de la primera
     * parada si el camión no tiene base conocida).
     *
     * @param  list<ResolvedStop>                            $stops
     * @return array{0: list<string>, 1: array<string, Eta>}
     */
    private function etasInReceivedOrder(string $vehicleId, DateTimeImmutable $departAt, array $stops): array
    {
        $vehicleId = strtolower(trim($vehicleId));
        $base = ($this->vehicles->vehiclesFor([$vehicleId])[$vehicleId] ?? null)?->base();

        $route = $this->routes->calculate(
            $base,
            $departAt,
            array_map(
                static fn (array $stop): array => [
                    'shipment_id' => $stop['shipment_id'],
                    'point' => GeoPoint::of($stop['latitude'], $stop['longitude']),
                    'window' => $stop['window'],
                ],
                $stops,
            ),
            reorder: false,
        );

        $etas = [];
        foreach ($route['order'] as $shipmentId) {
            $minutes = (int) round($route['arrivals'][$shipmentId] / 60.0);
            $etas[$shipmentId] = Eta::at($departAt->modify("+{$minutes} minutes"), $minutes);
        }

        return [$route['order'], $etas];
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
