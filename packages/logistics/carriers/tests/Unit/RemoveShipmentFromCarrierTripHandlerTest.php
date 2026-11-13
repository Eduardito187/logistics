<?php

declare(strict_types=1);

use Logistics\Carriers\Application\Command\RemoveShipmentFromCarrierTrip\RemoveShipmentFromCarrierTrip;
use Logistics\Carriers\Application\Command\RemoveShipmentFromCarrierTrip\RemoveShipmentFromCarrierTripHandler;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Handler que quita un envío de un viaje programado: valida existencia, quita, guarda y publica el evento.
 */
function removeTripShipHandlerClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-13T12:00:00+00:00');
        }
    };
}

function removeTripShipHandlerOutbox(): OutboxStore
{
    return new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $messages = [];

        public function append(OutboxMessage $message): void
        {
            $this->messages[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };
}

function removeTripShipHandlerRepo(?CarrierTrip $trip): CarrierTripRepository
{
    return new class($trip) implements CarrierTripRepository
    {
        public int $saves = 0;

        public function __construct(private ?CarrierTrip $trip) {}

        public function nextIdentity(): CarrierTripId
        {
            return CarrierTripId::generate();
        }

        public function save(CarrierTrip $trip): void
        {
            $this->trip = $trip;
            $this->saves++;
        }

        public function findById(CarrierTripId $id): ?CarrierTrip
        {
            return $this->trip;
        }

        public function findByIdForUpdate(CarrierTripId $id): ?CarrierTrip
        {
            return $this->trip;
        }

        public function shipmentsHeldByOtherActiveTrips(array $shipmentIds, ?CarrierTripId $except = null): array
        {
            return [];
        }
    };
}

function removeTripShipHandlerTrip(): CarrierTrip
{
    return CarrierTrip::schedule(
        CarrierTripId::generate(),
        '0190a0b0-0000-7000-8000-0000000000aa',
        'CD-SCZ',
        'CD-LPZ',
        new DateTimeImmutable('2026-11-13 08:00:00'),
        'MAN-001',
        ['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002'],
    );
}

it('quita el envío del viaje, guarda y publica el evento', function (): void {
    $trip = removeTripShipHandlerTrip();
    $trip->releaseEvents();
    $repo = removeTripShipHandlerRepo($trip);
    $outbox = removeTripShipHandlerOutbox();
    $handler = new RemoveShipmentFromCarrierTripHandler(
        $repo,
        new RecordDomainEvents($outbox, removeTripShipHandlerClock()),
    );

    $handler->handle(new RemoveShipmentFromCarrierTrip(
        $trip->id()->toString(),
        '0190a0b0-0000-7000-8000-000000000001',
    ));

    expect($repo->saves)->toBe(1)
        ->and($trip->shipmentIds())->toBe(['0190a0b0-0000-7000-8000-000000000002'])
        ->and($outbox->messages)->toHaveCount(1)
        ->and($outbox->messages[0]->eventName())->toBe('carriers.carrier_trip_shipment_removed');
});

it('falla con CarrierTripNotFound cuando el viaje no existe', function (): void {
    $handler = new RemoveShipmentFromCarrierTripHandler(
        removeTripShipHandlerRepo(null),
        new RecordDomainEvents(removeTripShipHandlerOutbox(), removeTripShipHandlerClock()),
    );

    expect(fn () => $handler->handle(new RemoveShipmentFromCarrierTrip(
        CarrierTripId::generate()->toString(),
        '0190a0b0-0000-7000-8000-000000000001',
    )))->toThrow(CarrierTripNotFound::class);
});
