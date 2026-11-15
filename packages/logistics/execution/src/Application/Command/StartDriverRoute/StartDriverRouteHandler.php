<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\StartDriverRoute;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;
use Logistics\Execution\Application\Service\DriverRouteLocator;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Inicia la ruta del día: la ruta pasa a en curso (DriverRouteStarted) y cada envío de una parada
 * PENDIENTE cuya entrega ya está cargada SALE (Delivery::markDeparted → ShipmentDeparted, que el reactor de
 * shipments lleva a IN_EXECUTION y tracking a «en camino»). Es la misma salida que dispara el botón web del
 * chofer, ahora desde la app.
 *
 * Un camión NO APTO (ítem crítico del checklist pre-salida fallido y no liberado) no inicia una salida: se
 * rechaza con el motivo (regla de negocio → 422). Aplica a CADA salida del día, también a la segunda con
 * el mismo camión; el reintento del inicio de una ruta que ya salió sigue siendo idempotente.
 *
 * Idempotente: si la ruta ya estaba iniciada no se reemite nada; markDeparted también es no-op si la
 * entrega ya salió. Un envío sin entrega abierta no frena al resto. El conjunto corre en la transacción del bus.
 *
 * @implements CommandHandler<StartDriverRoute>
 */
final readonly class StartDriverRouteHandler implements CommandHandler
{
    public function __construct(
        private DriverRouteLocator $locator,
        private VehicleRouteRepository $routes,
        private DeliveryRepository $deliveries,
        private RecordDomainEvents $recordEvents,
        private ?VehicleFitnessReader $fitness = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof StartDriverRoute) {
            throw ExecutionException::because('StartDriverRouteHandler solo maneja StartDriverRoute');
        }

        $route = $this->locator->locate($command->vehicleId, $command->routeDate, $command->occurredAt);
        if ($route === null) {
            throw ExecutionException::because("el vehículo {$command->vehicleId} no tiene ruta armada para iniciar");
        }

        // Aptitud del pre-salida ligada a la salida en curso: solo frena el inicio de una ruta sin iniciar.
        if ($route->startedAt() === null && $this->fitness?->isUnfit($route->vehicleId()) === true) {
            throw ExecutionException::because(VehicleFitnessReader::UNFIT_DETAIL);
        }

        $at = new DateTimeImmutable($command->occurredAt);
        if (!$route->start($at, $command->odometerKm, GeoPoint::fromNullable($command->lat, $command->lng))) {
            return;
        }

        $this->routes->save($route);
        $this->recordEvents->record($route->releaseEvents(), 'execution.route');

        foreach ($route->remainingStops() as $stop) {
            $delivery = $this->deliveries->findByShipmentId($stop->shipmentId());
            if ($delivery === null) {
                continue;
            }

            $delivery->markDeparted($at);
            $events = $delivery->releaseEvents();
            if ($events === []) {
                continue;
            }

            $this->deliveries->save($delivery);
            $this->recordEvents->record($events, 'execution.delivery');
        }
    }
}
