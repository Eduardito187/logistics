<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AttachShipmentToRoute;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentRouteFactsReader;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Suma el envío a la RUTA DE HOY del vehículo (o arma una ruta nueva para hoy si no tiene una abierta).
 *
 * Regla de la ruta del día: contiene las paradas del plan publicado de ese camión y día más las que se le
 * suman explícitamente ESE día. Por eso la asignación apunta siempre a la ruta de HOY: antes tomaba
 * «cualquier ruta abierta del camión» (la de fecha más alta), así que una asignación caía en una ruta de
 * otro día —una vieja sin cerrar o una futura ya armada— y los pedidos se arrastraban de una jornada a otra.
 *
 *  - Un envío YA ENTREGADO no se suma a ninguna ruta (no-op): nunca es una parada de hoy.
 *  - Si el envío tenía una parada PENDIENTE en otra ruta abierta (otro día u otro camión), sale de allí:
 *    el pedido queda en una sola ruta.
 *  - La parada copia las coordenadas del destino del envío (si las tiene) y guarda cuándo se sumó.
 *
 * Idempotente: si el envío ya es una parada de la ruta de hoy, el agregado no hace nada. Rechaza (regla de
 * negocio → 422) un vehículo marcado NO APTO por el checklist pre-viaje y una ruta de hoy suspendida por un
 * incidente. El save del repositorio y el append al outbox corren en la misma transacción (decorador del
 * CommandBus).
 *
 * @implements CommandHandler<AttachShipmentToRoute>
 */
final readonly class AttachShipmentToRouteHandler implements CommandHandler
{
    public function __construct(
        private VehicleRouteRepository $routes,
        private RecordDomainEvents $recordEvents,
        private ShipmentRouteFactsReader $facts,
        private ?VehicleFitnessReader $fitness = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AttachShipmentToRoute) {
            throw ExecutionException::because('AttachShipmentToRouteHandler solo maneja AttachShipmentToRoute');
        }

        // Vehículo no apto (ítem crítico del pre-viaje fallido y no liberado): no sale a reparto.
        if ($this->fitness?->isUnfit($command->vehicleId) === true) {
            throw ExecutionException::because(VehicleFitnessReader::UNFIT_DETAIL);
        }

        // Envío ya entregado: no es una parada de ninguna ruta de hoy.
        if (isset($this->facts->closedDays([$command->shipmentId])[$command->shipmentId])) {
            return;
        }

        $now = new DateTimeImmutable;
        $today = RouteDate::fromString($now->format('Y-m-d'));
        $route = $this->routes->findByVehicleAndDate($command->vehicleId, $today);

        if ($route?->status() === RouteStatus::Suspended) {
            throw ExecutionException::because('la ruta de hoy del vehículo está suspendida por un incidente: no se le suman paradas');
        }

        if ($route !== null && $route->hasShipment($command->shipmentId)) {
            return;
        }

        $this->releaseFromOtherRoute($command->shipmentId, $now);

        $point = $this->facts->coordinates([$command->shipmentId])[$command->shipmentId] ?? null;

        if ($route === null || $route->status() === RouteStatus::Completed) {
            // Sin ruta abierta hoy (o la de hoy ya terminó: es otra salida): se arma una para hoy con este
            // único envío como primera parada.
            $route = VehicleRoute::assemble(
                $this->routes->nextIdentity(),
                $command->vehicleId,
                $today,
                [RouteStop::plan(
                    RouteStopId::generate(),
                    $command->shipmentId,
                    1,
                    $command->orderReference,
                    $command->customerName,
                    $command->address,
                    null,
                    $point['lat'] ?? null,
                    $point['lng'] ?? null,
                    $now,
                )],
            );
        } else {
            $route->attachShipment(
                RouteStopId::generate(),
                $command->shipmentId,
                $command->orderReference,
                $command->customerName,
                $command->address,
                $now,
                $point['lat'] ?? null,
                $point['lng'] ?? null,
            );
        }

        $this->routes->save($route);
        $this->recordEvents->record($route->releaseEvents(), 'execution.route');
    }

    /**
     * Saca la parada PENDIENTE que el envío tuviera en otra ruta todavía abierta (un día anterior sin cerrar,
     * u otro camión), para que no figure en dos hojas de ruta. Una parada ya atendida o una ruta cerrada se
     * respetan: el agregado solo desengancha pendientes.
     */
    private function releaseFromOtherRoute(string $shipmentId, DateTimeImmutable $now): void
    {
        $other = $this->routes->findByShipmentId($shipmentId);
        if ($other === null || $other->isClosed()) {
            return;
        }

        if ($other->detachShipment($shipmentId, $now)) {
            $this->routes->save($other);
            $this->recordEvents->record($other->releaseEvents(), 'execution.route');
        }
    }
}
