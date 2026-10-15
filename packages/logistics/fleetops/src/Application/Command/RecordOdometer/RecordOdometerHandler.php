<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordOdometer;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
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

        $reading = OdometerReading::record(
            $this->readings->nextIdentity(),
            $command->vehicleId,
            Odometer::km($command->km),
            OdometerSource::from($command->source),
            new DateTimeImmutable($command->readAt),
            $command->note,
        );

        $this->readings->save($reading);

        $this->recordEvents->record($reading->releaseEvents(), 'fleetops.odometer_reading');
    }
}
