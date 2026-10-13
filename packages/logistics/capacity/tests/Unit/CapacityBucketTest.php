<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Event\CapacityConsumed;
use Logistics\Capacity\Domain\Event\CapacityDefined;
use Logistics\Capacity\Domain\Event\CapacityReleased;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

function defineBucket(float $total = 10.0): CapacityBucket
{
    return CapacityBucket::define(
        CapacityBucketId::generate(),
        BucketScope::forNode('11111111-1111-7111-8111-111111111111'),
        SlotDate::fromString('2026-10-13'),
        TimeSlot::fromString('09:00-13:00'),
        CapacityUnit::Deliveries,
        Quantity::of($total),
    );
}

it('defines a bucket with zero used and records CapacityDefined', function (): void {
    $bucket = defineBucket(10.0);

    expect($bucket->total()->value())->toBe(10.0)
        ->and($bucket->used()->value())->toBe(0.0)
        ->and($bucket->available()->value())->toBe(10.0)
        ->and($bucket->isFull())->toBeFalse()
        ->and($bucket->scope()->nodeId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($bucket->unit())->toBe(CapacityUnit::Deliveries);

    $events = $bucket->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CapacityDefined::class)
        ->and($events[0]->eventName())->toBe('capacity.capacity_defined')
        ->and($events[0]->aggregateId())->toBe($bucket->id()->toString())
        ->and($events[0]->payload())->toBe([
            'node_id' => '11111111-1111-7111-8111-111111111111',
            'zone_id' => null,
            'date' => '2026-10-13',
            'time_slot' => '09:00-13:00',
            'unit' => 'deliveries',
            'total' => 10.0,
        ]);
});

it('consumes capacity and reduces the available amount', function (): void {
    $bucket = defineBucket(10.0);
    $bucket->releaseEvents();

    $bucket->consume(Quantity::of(4.0));

    expect($bucket->used()->value())->toBe(4.0)
        ->and($bucket->available()->value())->toBe(6.0)
        ->and($bucket->isFull())->toBeFalse();

    $events = $bucket->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CapacityConsumed::class)
        ->and($events[0]->eventName())->toBe('capacity.capacity_consumed')
        ->and($events[0]->payload())->toBe([
            'unit' => 'deliveries',
            'amount' => 4.0,
            'used' => 4.0,
            'available' => 6.0,
        ]);
});

it('consumes up to the exact total and becomes full', function (): void {
    $bucket = defineBucket(10.0);

    $bucket->consume(Quantity::of(10.0));

    expect($bucket->available()->value())->toBe(0.0)
        ->and($bucket->available()->isZero())->toBeTrue()
        ->and($bucket->isFull())->toBeTrue();
});

it('throws when consuming more than available', function (): void {
    $bucket = defineBucket(10.0);
    $bucket->consume(Quantity::of(7.0));

    expect(fn () => $bucket->consume(Quantity::of(4.0)))
        ->toThrow(CapacityException::class, 'excede');

    // El estado no cambió: el consumo que excede no se aplica.
    expect($bucket->used()->value())->toBe(7.0)
        ->and($bucket->available()->value())->toBe(3.0);
});

it('releases capacity and gives it back', function (): void {
    $bucket = defineBucket(10.0);
    $bucket->consume(Quantity::of(8.0));
    $bucket->releaseEvents();

    $bucket->release(Quantity::of(3.0));

    expect($bucket->used()->value())->toBe(5.0)
        ->and($bucket->available()->value())->toBe(5.0);

    $events = $bucket->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CapacityReleased::class)
        ->and($events[0]->eventName())->toBe('capacity.capacity_released')
        ->and($events[0]->payload())->toBe([
            'unit' => 'deliveries',
            'amount' => 3.0,
            'used' => 5.0,
            'available' => 5.0,
        ]);
});

it('never drops used below zero when releasing more than consumed', function (): void {
    $bucket = defineBucket(10.0);
    $bucket->consume(Quantity::of(2.0));

    $bucket->release(Quantity::of(5.0));

    expect($bucket->used()->value())->toBe(0.0)
        ->and($bucket->available()->value())->toBe(10.0);
});

it('rehydrates from state without recording events', function (): void {
    $bucket = CapacityBucket::fromState(
        CapacityBucketId::generate(),
        BucketScope::forZone('22222222-2222-7222-8222-222222222222'),
        SlotDate::fromString('2026-10-13'),
        TimeSlot::fromString('14:00-18:00'),
        CapacityUnit::Weight,
        Quantity::of(500.0),
        Quantity::of(120.5),
    );

    expect($bucket->available()->value())->toBe(379.5)
        ->and($bucket->scope()->zoneId)->toBe('22222222-2222-7222-8222-222222222222')
        ->and($bucket->pendingEvents())->toHaveCount(0);
});
