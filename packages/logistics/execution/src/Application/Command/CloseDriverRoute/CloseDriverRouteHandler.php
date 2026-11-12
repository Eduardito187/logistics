<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\CloseDriverRoute;

use DateTimeImmutable;
use Logistics\Execution\Application\Service\DriverRouteLocator;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cierra la ruta del día: pasa a Completed (DriverRouteClosed con las paradas pendientes que quedaron).
 * No toca las paradas pendientes ni sus entregas: quedan como están para que operaciones decida
 * (reprogramar, devolver). Idempotente: si ya estaba cerrada no se reemite nada.
 *
 * @implements CommandHandler<CloseDriverRoute>
 */
final readonly class CloseDriverRouteHandler implements CommandHandler
{
    public function __construct(
        private DriverRouteLocator $locator,
        private VehicleRouteRepository $routes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof CloseDriverRoute) {
            throw ExecutionException::because('CloseDriverRouteHandler solo maneja CloseDriverRoute');
        }

        $route = $this->locator->locate($command->vehicleId, $command->routeDate, $command->occurredAt);
        if ($route === null) {
            throw ExecutionException::because("el vehículo {$command->vehicleId} no tiene ruta para cerrar");
        }

        $closed = $route->close(
            new DateTimeImmutable($command->occurredAt),
            $command->odometerKm,
            GeoPoint::fromNullable($command->lat, $command->lng),
        );
        if (!$closed) {
            return;
        }

        $this->routes->save($route);
        $this->recordEvents->record($route->releaseEvents(), 'execution.route');
    }
}
