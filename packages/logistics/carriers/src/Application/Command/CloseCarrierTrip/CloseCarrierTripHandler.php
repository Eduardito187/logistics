<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\CloseCarrierTrip;

use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Cierra el viaje con la fila bloqueada (FOR UPDATE): reintentos simultáneos se serializan y el segundo
 * es un no-op (sin eventos duplicados). Solo persiste y publica si hubo transición.
 *
 * @implements CommandHandler<CloseCarrierTrip>
 */
final readonly class CloseCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CloseCarrierTrip);

        $trip = $this->trips->findByIdForUpdate(CarrierTripId::fromString($command->tripId));
        if ($trip === null) {
            throw CarrierTripNotFound::withId($command->tripId);
        }

        if (!$trip->close($this->clock->now(), $command->actorId)) {
            return;
        }

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
