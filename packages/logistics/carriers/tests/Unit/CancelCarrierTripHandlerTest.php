<?php

declare(strict_types=1);

use Logistics\Carriers\Application\Command\CancelCarrierTrip\CancelCarrierTrip;
use Logistics\Carriers\Application\Command\CancelCarrierTrip\CancelCarrierTripHandler;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Handler que cancela un viaje programado: valida existencia, cancela, guarda y publica el evento; es
 * idempotente sobre uno ya cancelado (no guarda ni publica de nuevo).
 */
function cancelTripHandlerClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-13T12:00:00+00:00');
        }
    };
}

function cancelTripHandlerOutbox(): OutboxStore
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

function cancelTripHandlerRepo(?CarrierTrip $trip): CarrierTripRepository
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

function cancelTripHandlerTrip(): CarrierTrip
{
    return CarrierTrip::schedule(
        CarrierTripId::generate(),
        '0190a0b0-0000-7000-8000-0000000000aa',
        'CD-SCZ',
        'CD-LPZ',
        new DateTimeImmutable('2026-11-13 08:00:00'),
        'MAN-001',
        ['0190a0b0-0000-7000-8000-000000000001'],
    );
}

it('cancela un viaje programado, guarda y publica el evento', function (): void {
    $trip = cancelTripHandlerTrip();
    $trip->releaseEvents();
    $repo = cancelTripHandlerRepo($trip);
    $outbox = cancelTripHandlerOutbox();
    $handler = new CancelCarrierTripHandler(
        $repo,
        new RecordDomainEvents($outbox, cancelTripHandlerClock()),
        cancelTripHandlerClock(),
    );

    $handler->handle(new CancelCarrierTrip($trip->id()->toString(), 'Truck broke down'));

    expect($repo->saves)->toBe(1)
        ->and($trip->status())->toBe(CarrierTripStatus::Cancelled)
        ->and($outbox->messages)->toHaveCount(1)
        ->and($outbox->messages[0]->eventName())->toBe('carriers.carrier_trip_cancelled');
});

it('es idempotente sobre un viaje ya cancelado (no guarda ni publica)', function (): void {
    $trip = cancelTripHandlerTrip();
    $trip->cancel(new DateTimeImmutable('2026-11-13 07:00:00'), 'Primera');
    $trip->releaseEvents();
    $repo = cancelTripHandlerRepo($trip);
    $outbox = cancelTripHandlerOutbox();
    $handler = new CancelCarrierTripHandler(
        $repo,
        new RecordDomainEvents($outbox, cancelTripHandlerClock()),
        cancelTripHandlerClock(),
    );

    $handler->handle(new CancelCarrierTrip($trip->id()->toString()));

    expect($repo->saves)->toBe(0)
        ->and($outbox->messages)->toBe([]);
});

it('falla con CarrierTripNotFound cuando el viaje no existe', function (): void {
    $handler = new CancelCarrierTripHandler(
        cancelTripHandlerRepo(null),
        new RecordDomainEvents(cancelTripHandlerOutbox(), cancelTripHandlerClock()),
        cancelTripHandlerClock(),
    );

    expect(fn () => $handler->handle(new CancelCarrierTrip(CarrierTripId::generate()->toString())))
        ->toThrow(CarrierTripNotFound::class);
});
