<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\AssignShipmentsToCarrierTrip;

use Logistics\Carriers\Application\Service\ShipmentLoadGuard;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Agrega envíos a un viaje programado (bloqueando la fila), validando existencia y exclusividad.
 *
 * @implements CommandHandler<AssignShipmentsToCarrierTrip>
 */
final readonly class AssignShipmentsToCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private ShipmentLoadGuard $loadGuard,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AssignShipmentsToCarrierTrip);

        $id = CarrierTripId::fromString($command->tripId);
        $trip = $this->trips->findByIdForUpdate($id);
        if ($trip === null) {
            throw CarrierTripNotFound::withId($command->tripId);
        }

        $added = $trip->assignShipments($command->shipmentIds);
        if ($added === []) {
            return;
        }

        $this->loadGuard->assertLoadable($added, $id);

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
