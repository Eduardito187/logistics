<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\MarkStopArrived;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\ArrivalSource;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la llegada del camión a la parada del envío en la ruta que lo contiene y graba
 * DriverRouteStopArrived (execution.stop_arrived). Idempotente: si la parada ya tenía llegada, no hace nada.
 *
 * @implements CommandHandler<MarkStopArrived>
 */
final readonly class MarkStopArrivedHandler implements CommandHandler
{
    public function __construct(
        private VehicleRouteRepository $routes,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof MarkStopArrived) {
            throw ExecutionException::because('MarkStopArrivedHandler solo maneja MarkStopArrived');
        }

        $source = ArrivalSource::tryFrom($command->source);
        if ($source === null) {
            throw ExecutionException::because("origen de llegada inválido: {$command->source}");
        }

        $route = $this->routes->findByShipmentId($command->shipmentId);
        if ($route === null) {
            throw ExecutionException::because("el envío {$command->shipmentId} no es parada de ninguna ruta");
        }

        $arrived = $route->arriveAtStop(
            $command->shipmentId,
            new DateTimeImmutable($command->occurredAt),
            $source,
            GeoPoint::fromNullable($command->lat, $command->lng, $command->accuracyM),
        );
        if (!$arrived) {
            return;
        }

        $this->routes->save($route);
        $this->recordEvents->record($route->releaseEvents(), 'execution.route');
    }
}
