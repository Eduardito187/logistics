<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AttachShipmentToRoute;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\VehicleFitnessReader;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega el envío a la ruta abierta del vehículo, o arma una ruta nueva para hoy si no tiene ninguna.
 * Idempotente: si el envío ya es una parada de esa ruta, el agregado no hace nada. Rechaza (regla de
 * negocio → 422) un vehículo marcado NO APTO por el checklist pre-viaje: no se le suman paradas. El save del
 * repositorio y el append al outbox corren en la misma transacción (decorador del CommandBus).
 *
 * @implements CommandHandler<AttachShipmentToRoute>
 */
final readonly class AttachShipmentToRouteHandler implements CommandHandler
{
    public function __construct(
        private VehicleRouteRepository $routes,
        private RecordDomainEvents $recordEvents,
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

        $now = new DateTimeImmutable;
        $route = $this->routes->findOpenByVehicleId($command->vehicleId);

        if ($route === null) {
            // Sin ruta abierta: se arma una para hoy con este único envío como primera parada.
            $route = VehicleRoute::assemble(
                $this->routes->nextIdentity(),
                $command->vehicleId,
                RouteDate::fromString($now->format('Y-m-d')),
                [RouteStop::plan(
                    RouteStopId::generate(),
                    $command->shipmentId,
                    1,
                    $command->orderReference,
                    $command->customerName,
                    $command->address,
                    null,
                    null,
                    null,
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
            );
        }

        $this->routes->save($route);
        $this->recordEvents->record($route->releaseEvents(), 'execution.route');
    }
}
