<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\RemoveShipmentFromCarrierTrip;

use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Quita un envío de un viaje programado y publica el evento al outbox.
 *
 * @implements CommandHandler<RemoveShipmentFromCarrierTrip>
 */
final readonly class RemoveShipmentFromCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof RemoveShipmentFromCarrierTrip);

        $trip = $this->trips->findByIdForUpdate(CarrierTripId::fromString($command->tripId));
        if ($trip === null) {
            throw CarrierTripNotFound::withId($command->tripId);
        }

        $trip->removeShipment($command->shipmentId);

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
