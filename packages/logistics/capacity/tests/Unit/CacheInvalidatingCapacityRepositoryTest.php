<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Capacity\Infrastructure\Cache\CacheInvalidatingCapacityRepository;
use Logistics\Capacity\Infrastructure\Cache\CapacityGenerationKey;

/**
 * Repositorio en memoria que solo cuenta los save, para aislar el decorador de la base.
 */
function fakeCapacityRepository(): CapacityRepository
{
    return new class implements CapacityRepository
    {
        public int $saves = 0;

        public function nextIdentity(): CapacityBucketId
        {
            return CapacityBucketId::generate();
        }

        public function save(CapacityBucket $bucket): void
        {
            $this->saves++;
        }

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
}

function sampleBucket(string $date): CapacityBucket
{
    return CapacityBucket::define(
        CapacityBucketId::generate(),
        BucketScope::forZone('11111111-1111-7111-8111-111111111111'),
        SlotDate::fromString($date),
        TimeSlot::fromString('09:00-13:00'),
        CapacityUnit::Deliveries,
        Quantity::of(10.0),
    );
}

it('delegates the save to the inner repository', function (): void {
    $inner = fakeCapacityRepository();
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CacheInvalidatingCapacityRepository($inner, $cache);

    $repo->save(sampleBucket('2026-10-20'));

    expect($inner->saves)->toBe(1);
});

it('bumps the day generation counter on every save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CacheInvalidatingCapacityRepository(fakeCapacityRepository(), $cache);
    $key = CapacityGenerationKey::forDate('2026-10-20');

    expect($cache->get($key, 0))->toBe(0);

    $repo->save(sampleBucket('2026-10-20'));
    expect((int) $cache->get($key, 0))->toBe(1);

    $repo->save(sampleBucket('2026-10-20'));
    expect((int) $cache->get($key, 0))->toBe(2);
});

it('keeps a separate generation counter per day', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CacheInvalidatingCapacityRepository(fakeCapacityRepository(), $cache);

    $repo->save(sampleBucket('2026-10-20'));
    $repo->save(sampleBucket('2026-10-21'));

    expect((int) $cache->get(CapacityGenerationKey::forDate('2026-10-20'), 0))->toBe(1)
        ->and((int) $cache->get(CapacityGenerationKey::forDate('2026-10-21'), 0))->toBe(1);
});
