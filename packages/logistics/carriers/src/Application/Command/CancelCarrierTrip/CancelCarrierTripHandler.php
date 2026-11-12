<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\CancelCarrierTrip;

use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Cancela un viaje programado (fila bloqueada) y publica el evento. Idempotente sobre uno ya cancelado.
 *
 * @implements CommandHandler<CancelCarrierTrip>
 */
final readonly class CancelCarrierTripHandler implements CommandHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
        private RecordDomainEvents $recordEvents,
        private Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof CancelCarrierTrip);

        $trip = $this->trips->findByIdForUpdate(CarrierTripId::fromString($command->tripId));
        if ($trip === null) {
            throw CarrierTripNotFound::withId($command->tripId);
        }

        if (!$trip->cancel($this->clock->now(), $command->reason)) {
            return;
        }

        $this->trips->save($trip);

        $this->recordEvents->record($trip->releaseEvents(), 'carriers.carrier_trip');
    }
}
