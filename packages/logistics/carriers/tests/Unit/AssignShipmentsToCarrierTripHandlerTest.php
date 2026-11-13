<?php

declare(strict_types=1);

use Logistics\Carriers\Application\Command\AssignShipmentsToCarrierTrip\AssignShipmentsToCarrierTrip;
use Logistics\Carriers\Application\Command\AssignShipmentsToCarrierTrip\AssignShipmentsToCarrierTripHandler;
use Logistics\Carriers\Application\Contract\CarrierAssignmentReader;
use Logistics\Carriers\Application\Service\ShipmentLoadGuard;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Handler que agrega envíos a un viaje programado: valida existencia del viaje, arma la carga, aplica el
 * guard de carga y publica el evento; hace early-return cuando no hay nada nuevo que agregar.
 */
function assignTripShipHandlerClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-13T12:00:00+00:00');
        }
    };
}

function assignTripShipHandlerOutbox(): OutboxStore
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

/**
 * Repositorio de viajes en memoria: guarda a lo sumo un viaje y cuenta los save.
 */
function assignTripShipHandlerRepo(?CarrierTrip $trip): CarrierTripRepository
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

/**
 * Lector de asignaciones que da por existentes todos los envíos que se le pasan.
 */
function assignTripShipHandlerReader(): CarrierAssignmentReader
{
    return new class implements CarrierAssignmentReader
    {
        public function assignments(?string $carrierId, array $statuses, int $limit, int $offset): array
        {
            return [];
        }

        public function count(?string $carrierId, array $statuses): int
        {
            return 0;
        }

        public function existingShipmentIds(array $shipmentIds): array
        {
            return array_values($shipmentIds);
        }
    };
}

function assignTripShipHandlerTrip(): CarrierTrip
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

it('agrega los envíos nuevos, guarda y publica el evento', function (): void {
    $trip = assignTripShipHandlerTrip();
    $trip->releaseEvents();
    $repo = assignTripShipHandlerRepo($trip);
    $outbox = assignTripShipHandlerOutbox();
    $handler = new AssignShipmentsToCarrierTripHandler(
        $repo,
        new ShipmentLoadGuard(assignTripShipHandlerReader(), $repo),
        new RecordDomainEvents($outbox, assignTripShipHandlerClock()),
    );

    $handler->handle(new AssignShipmentsToCarrierTrip($trip->id()->toString(), [
        '0190a0b0-0000-7000-8000-000000000002',
    ]));

    expect($repo->saves)->toBe(1)
        ->and($trip->shipmentIds())->toBe([
            '0190a0b0-0000-7000-8000-000000000001',
            '0190a0b0-0000-7000-8000-000000000002',
        ])
        ->and($outbox->messages)->toHaveCount(1)
        ->and($outbox->messages[0]->eventName())->toBe('carriers.carrier_trip_shipments_assigned');
});

it('hace early-return sin guardar cuando no hay envíos nuevos', function (): void {
    $trip = assignTripShipHandlerTrip();
    $trip->releaseEvents();
    $repo = assignTripShipHandlerRepo($trip);
    $outbox = assignTripShipHandlerOutbox();
    $handler = new AssignShipmentsToCarrierTripHandler(
        $repo,
        new ShipmentLoadGuard(assignTripShipHandlerReader(), $repo),
        new RecordDomainEvents($outbox, assignTripShipHandlerClock()),
    );

    $handler->handle(new AssignShipmentsToCarrierTrip($trip->id()->toString(), [
        '0190a0b0-0000-7000-8000-000000000001',
    ]));

    expect($repo->saves)->toBe(0)
        ->and($outbox->messages)->toBe([]);
});

it('falla con CarrierTripNotFound cuando el viaje no existe', function (): void {
    $repo = assignTripShipHandlerRepo(null);
    $handler = new AssignShipmentsToCarrierTripHandler(
        $repo,
        new ShipmentLoadGuard(assignTripShipHandlerReader(), $repo),
        new RecordDomainEvents(assignTripShipHandlerOutbox(), assignTripShipHandlerClock()),
    );

    expect(fn () => $handler->handle(new AssignShipmentsToCarrierTrip(
        CarrierTripId::generate()->toString(),
        ['0190a0b0-0000-7000-8000-000000000002'],
    )))->toThrow(CarrierTripNotFound::class);
});
