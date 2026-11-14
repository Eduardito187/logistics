<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordOdometer;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Exception\RouteAlreadyClosed;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Construye la lectura de odómetro, la persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<RecordOdometer>
 */
final readonly class RecordOdometerHandler implements CommandHandler
{
    public function __construct(
        private OdometerRepository $readings,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RecordOdometer);

        $source = OdometerSource::from($command->source);
        $routeId = $command->routeId !== null && trim($command->routeId) !== '' ? trim($command->routeId) : null;

        // Una ruta se cierra UNA sola vez: una segunda lectura de llegada de la misma ruta es un conflicto.
        if ($routeId !== null && $source === OdometerSource::Arrival
            && $this->readings->existsForRouteAndSource($routeId, OdometerSource::Arrival)) {
            throw RouteAlreadyClosed::forRoute($routeId);
        }

        $reading = OdometerReading::record(
            $this->readings->nextIdentity(),
            $command->vehicleId,
            Odometer::km($command->km),
            $source,
            new DateTimeImmutable($command->readAt),
            $command->note,
            $routeId,
        );

        $this->readings->save($reading);

        $this->recordEvents->record($reading->releaseEvents(), 'fleetops.odometer_reading');
    }
}
