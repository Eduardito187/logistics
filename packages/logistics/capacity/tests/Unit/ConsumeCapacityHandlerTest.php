<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityHandler;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityHandler;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;

const BUCKET_NODE = '11111111-1111-7111-8111-111111111111';

/**
 * Repositorio de cupos en memoria para probar el handler sin base de datos. Registra si se pidió el
 * bucket con lock pesimista (findBucketForUpdate) o sin él, para verificar que el consumo siempre
 * serializa la franja.
 */
function inMemoryBuckets(CapacityBucket $bucket): CapacityRepository
{
    return new class($bucket) implements CapacityRepository
    {
        public bool $lockedRead = false;

        public bool $unlockedRead = false;

        public int $saves = 0;

        public function __construct(private CapacityBucket $bucket) {}

        public function nextIdentity(): CapacityBucketId
        {
            return CapacityBucketId::generate();
        }

        public function save(CapacityBucket $bucket): void
        {
            $this->bucket = $bucket;
            $this->saves++;
        }

        public function findById(CapacityBucketId $id): ?CapacityBucket
        {
            return $this->bucket;
        }

        public function findBucket(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            $this->unlockedRead = true;

            return $this->bucket;
        }

        public function findBucketForUpdate(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            $this->lockedRead = true;

            return $this->bucket;
        }

        public function byDate(SlotDate $date): array
        {
            return [$this->bucket];
        }
    };
}

/**
 * Colector de mensajes del outbox en memoria.
 */
function collectingOutbox(): OutboxStore
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
 * Reloj fijo para el outbox.
 */
function capacityFixedClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T12:00:00+00:00');
        }
    };
}

/**
 * Cupo de 10 entregas para el nodo de prueba, en la franja de la mañana.
 */
function tenDeliveryBucket(): CapacityBucket
{
    return CapacityBucket::define(
        CapacityBucketId::generate(),
        BucketScope::forNode(BUCKET_NODE),
        SlotDate::fromString('2026-10-13'),
        TimeSlot::fromString('09:00-13:00'),
        CapacityUnit::Deliveries,
        Quantity::of(10.0),
    );
}

function consumeCommand(float $amount): ConsumeCapacityCommand
{
    return new ConsumeCapacityCommand(BUCKET_NODE, null, '2026-10-13', '09:00-13:00', 'deliveries', $amount);
}

it('consumes capacity atomically under a pessimistic lock and records the event', function (): void {
    $bucket = tenDeliveryBucket();
    $bucket->releaseEvents();
    $repo = inMemoryBuckets($bucket);
    $outbox = collectingOutbox();
    $handler = new ConsumeCapacityHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock()));

    $handler->handle(consumeCommand(4.0));

    expect($bucket->used()->value())->toBe(4.0)
        ->and($bucket->available()->value())->toBe(6.0)
        // Siempre lee con lock (serializa la reserva concurrente), nunca sin él.
        ->and($repo->lockedRead)->toBeTrue()
        ->and($repo->unlockedRead)->toBeFalse()
        ->and($repo->saves)->toBe(1)
        ->and($outbox->messages)->toHaveCount(1)
        ->and($outbox->messages[0]->eventName())->toBe('capacity.capacity_consumed');
});

it('never over-reserves: a consumption beyond the available amount throws and leaves the bucket unchanged', function (): void {
    $bucket = tenDeliveryBucket();
    $repo = inMemoryBuckets($bucket);
    $handler = new ConsumeCapacityHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));

    $handler->handle(consumeCommand(7.0));

    expect(fn () => $handler->handle(consumeCommand(4.0)))
        ->toThrow(CapacityException::class, 'excede');

    // El consumo que excede no se aplica: el disponible no se corrompe.
    expect($bucket->used()->value())->toBe(7.0)
        ->and($bucket->available()->value())->toBe(3.0);
});

it('releases capacity back under the lock', function (): void {
    $bucket = tenDeliveryBucket();
    $repo = inMemoryBuckets($bucket);
    $outbox = collectingOutbox();

    (new ConsumeCapacityHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock())))->handle(consumeCommand(8.0));
    (new ReleaseCapacityHandler($repo, new RecordDomainEvents($outbox, capacityFixedClock())))->handle(
        new ReleaseCapacityCommand(BUCKET_NODE, null, '2026-10-13', '09:00-13:00', 'deliveries', 3.0),
    );

    expect($bucket->used()->value())->toBe(5.0)
        ->and($bucket->available()->value())->toBe(5.0)
        ->and($repo->lockedRead)->toBeTrue();
});

it('fails when no bucket exists for the natural key', function (): void {
    $repo = new class implements CapacityRepository
    {
        public function nextIdentity(): CapacityBucketId
        {
            return CapacityBucketId::generate();
        }

        public function save(CapacityBucket $bucket): void {}

        public function findById(CapacityBucketId $id): ?CapacityBucket
        {
            return null;
        }

        public function findBucket(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            return null;
        }

        public function findBucketForUpdate(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            return null;
        }

        public function byDate(SlotDate $date): array
        {
            return [];
        }
    };
    $handler = new ConsumeCapacityHandler($repo, new RecordDomainEvents(collectingOutbox(), capacityFixedClock()));

    expect(fn () => $handler->handle(consumeCommand(1.0)))
        ->toThrow(CapacityException::class, 'no existe un cupo');
});
