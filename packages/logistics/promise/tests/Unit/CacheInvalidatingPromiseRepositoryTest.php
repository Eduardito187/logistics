<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Infrastructure\Cache\CacheInvalidatingPromiseRepository;
use Logistics\Promise\Infrastructure\Cache\PromiseViewCacheKey;

/**
 * Repositorio en memoria que solo cuenta save, para aislar el decorador de la base.
 */
function fakePromiseRepository(): PromiseRepository
{
    return new class implements PromiseRepository
    {
        public int $saves = 0;

        public function nextIdentity(): PromiseId
        {
            return PromiseId::generate();
        }

        public function save(DeliveryPromise $promise): void
        {
            $this->saves++;
        }

        public function findById(PromiseId $id): ?DeliveryPromise
        {
            return null;
        }

        public function reservedExpiredAt(DateTimeImmutable $now): array
        {
            return [];
        }
    };
}

function samplePromise(string $id): DeliveryPromise
{
    return DeliveryPromise::reconstitute(
        PromiseId::fromString($id),
        'ORD-1',
        'zone-1',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), '09:00-13:00'),
        PromiseStatus::Reserved,
        null,
    );
}

it('delegates the save to the inner repository', function (): void {
    $inner = fakePromiseRepository();
    $repo = new CacheInvalidatingPromiseRepository($inner, new CacheRepository(new ArrayStore));

    $repo->save(samplePromise('00000000-0000-7000-8000-000000000001'));

    expect($inner->saves)->toBe(1);
});

it('forgets the cached view of the promise on save (event invalidation)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $id = '00000000-0000-7000-8000-000000000001';
    $key = PromiseViewCacheKey::forId($id);
    $cache->put($key, ['id' => $id, 'status' => 'reserved'], 300);

    $repo = new CacheInvalidatingPromiseRepository(fakePromiseRepository(), $cache);
    $repo->save(samplePromise($id));

    expect($cache->has($key))->toBeFalse();
});

it('only forgets the saved promise, leaving other cached views intact', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $kept = PromiseViewCacheKey::forId('00000000-0000-7000-8000-000000000002');
    $cache->put($kept, ['id' => 'other'], 300);

    $repo = new CacheInvalidatingPromiseRepository(fakePromiseRepository(), $cache);
    $repo->save(samplePromise('00000000-0000-7000-8000-000000000001'));

    expect($cache->has($kept))->toBeTrue();
});
