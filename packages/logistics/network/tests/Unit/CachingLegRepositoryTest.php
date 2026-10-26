<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Infrastructure\Cache\CachingLegRepository;
use Logistics\Network\Infrastructure\Cache\NetworkCacheKey;

/**
 * Repositorio en memoria que cuenta los all()/save()/remove() para aislar el decorador de la base.
 */
function fakeLegRepository(Leg ...$legs): LegRepository
{
    return new class(array_values($legs)) implements LegRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        public int $removes = 0;

        /** @param list<Leg> $legs */
        public function __construct(private array $legs) {}

        public function nextIdentity(): LegId
        {
            return LegId::generate();
        }

        public function save(Leg $leg): void
        {
            $this->saves++;
            $this->legs[] = $leg;
        }

        public function remove(Leg $leg): void
        {
            $this->removes++;
        }

        public function findById(LegId $id): ?Leg
        {
            return null;
        }

        public function findByEndpoints(NodeId $from, NodeId $to, CorridorType $corridor): ?Leg
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->legs;
        }
    };
}

function sampleLeg(CorridorType $corridor = CorridorType::CdToStore): Leg
{
    return Leg::fromState(LegId::generate(), NodeId::generate(), NodeId::generate(), $corridor);
}

it('serves the second all() from cache without hitting the inner repository', function (): void {
    $inner = fakeLegRepository(sampleLeg(), sampleLeg(CorridorType::CdToCd));
    $repo = new CachingLegRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(Leg::class)
        ->and($second[0]->corridor())->toBe(CorridorType::CdToStore);
});

it('stores the primitive array of each leg, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingLegRepository(fakeLegRepository(sampleLeg(CorridorType::CdToStore)), $cache);

    $repo->all();

    $stored = $cache->get(NetworkCacheKey::legsAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['corridor'])->toBe('cd_to_store')
        ->and($stored[0]['from'])->toBeString()
        ->and($stored[0]['to'])->toBeString();
});

it('invalidates the cached list on save and on remove', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingLegRepository(fakeLegRepository(sampleLeg()), $cache);

    $repo->all();
    expect($cache->has(NetworkCacheKey::legsAll()))->toBeTrue();
    $repo->save(sampleLeg());
    expect($cache->has(NetworkCacheKey::legsAll()))->toBeFalse();

    $repo->all();
    expect($cache->has(NetworkCacheKey::legsAll()))->toBeTrue();
    $repo->remove(sampleLeg());
    expect($cache->has(NetworkCacheKey::legsAll()))->toBeFalse();
});
