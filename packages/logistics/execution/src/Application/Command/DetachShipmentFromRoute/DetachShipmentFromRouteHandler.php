<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\DetachShipmentFromRoute;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Desengancha el envío de la ruta abierta del vehículo viejo al reasignar el pedido a otro camión. El
 * agregado solo saca la parada si sigue PENDIENTE (una ya atendida se respeta). Tolerante: sin ruta abierta
 * o sin la parada, no hace nada. El save del repositorio y el append al outbox van en la misma transacción.
 *
 * @implements CommandHandler<DetachShipmentFromRoute>
 */
final readonly class DetachShipmentFromRouteHandler implements CommandHandler
{
    public function __construct(
        private VehicleRouteRepository $routes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DetachShipmentFromRoute) {
            throw ExecutionException::because('DetachShipmentFromRouteHandler solo maneja DetachShipmentFromRoute');
        }

        // La ruta de ESE camión donde el envío tiene su parada (puede no ser la vigente: un pedido que quedó
        // en una ruta de otro día sin cerrar). Si no se ubica por el envío, la ruta abierta vigente.
        $route = $this->routes->findByShipmentId($command->shipmentId);
        if ($route === null || $route->vehicleId() !== $command->vehicleId || $route->isClosed()) {
            $route = $this->routes->findOpenByVehicleId($command->vehicleId);
        }
        if ($route === null) {
            return;
        }

        if ($route->detachShipment($command->shipmentId, new DateTimeImmutable)) {
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }
}
