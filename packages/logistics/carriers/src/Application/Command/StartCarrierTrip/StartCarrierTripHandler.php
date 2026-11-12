<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\StartCarrierTrip;

use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Inicia el viaje con la fila bloqueada (FOR UPDATE): dos reintentos simultáneos se serializan y el
 * segundo es un no-op (sin eventos duplicados). Solo persiste y publica si hubo transición.
 *
 * @implements CommandHandler<StartCarrierTrip>
 */
final readonly class StartCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof StartCarrierTrip);

        $trip = $this->trips->findByIdForUpdate(CarrierTripId::fromString($command->tripId));
        if ($trip === null) {
            throw CarrierTripNotFound::withId($command->tripId);
        }

        if (!$trip->start($this->clock->now(), $command->actorId)) {
            return;
        }

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
