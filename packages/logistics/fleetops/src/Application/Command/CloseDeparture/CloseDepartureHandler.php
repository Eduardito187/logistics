<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\CloseDeparture;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la salida, la cierra (calcula km recorridos), la persiste y publica sus eventos al outbox.
 *
 * @implements CommandHandler<CloseDeparture>
 */
final readonly class CloseDepartureHandler implements CommandHandler
{
    public function __construct(
        private DepartureRepository $departures,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CloseDeparture);

        $departure = $this->departures->findById(DepartureId::fromString($command->departureId));
        if ($departure === null) {
            throw FleetOpsException::because("No existe la salida {$command->departureId}.");
        }

        $departure->close(
            Odometer::km($command->odometerInKm),
            new DateTimeImmutable($command->returnedAt),
        );

        $this->departures->save($departure);

        $this->recordEvents->record($departure->releaseEvents(), 'fleetops.departure');
    }
}
