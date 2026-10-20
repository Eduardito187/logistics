<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;
use Logistics\Coverage\Infrastructure\Cache\CachingZoneRepository;
use Logistics\Coverage\Infrastructure\Cache\CoverageCacheKey;

function fakeZoneRepository(Zone ...$zones): ZoneRepository
{
    return new class(array_values($zones)) implements ZoneRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        /** @param list<Zone> $zones */
        public function __construct(private array $zones) {}

        public function nextIdentity(): ZoneId
        {
            return ZoneId::generate();
        }

        public function save(Zone $zone): void
        {
            $this->saves++;
            $this->zones[] = $zone;
        }

        public function findById(ZoneId $id): ?Zone
        {
            return null;
        }

        public function findByNameAndParent(string $name, ?GeoAreaId $parentAreaId): ?Zone
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->zones;
        }

        public function findZoneContaining(GeoPoint $point): ?Zone
        {
            return null;
        }
    };
}

function sampleZone(string $name = 'Zona Sur'): Zone
{
    return Zone::reconstitute(
        ZoneId::generate(),
        $name,
        GeoAreaId::generate(),
        Polygon::fromRing([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [0.0, 0.0]]),
        ZoneRules::fromArray(['baseMinutes' => 30, 'services' => ['STD']]),
        true,
    );
}

it('serves the second all() from cache and rehydrates the zone with its polygon and rules', function (): void {
    $inner = fakeZoneRepository(sampleZone('Zona Sur'));
    $repo = new CachingZoneRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(1)
        ->and($second[0])->toBeInstanceOf(Zone::class)
        ->and($second[0]->name())->toBe('Zona Sur')
        ->and($second[0]->rules()->baseMinutes)->toBe(30)
        ->and($second[0]->polygon()->ring)->toBe([[0.0, 0.0], [0.0, 1.0], [1.0, 1.0], [0.0, 0.0]])
        ->and($second[0]->isActive())->toBeTrue();
});

it('stores the primitive array of each zone, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingZoneRepository(fakeZoneRepository(sampleZone()), $cache);

    $repo->all();

    $stored = $cache->get(CoverageCacheKey::zonesAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['name'])->toBe('Zona Sur')
        ->and($stored[0]['ring'])->toBeArray()
        ->and($stored[0]['rules'])->toBeArray()
        ->and($stored[0]['active'])->toBeTrue();
});

it('invalidates the cached list on save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingZoneRepository(fakeZoneRepository(sampleZone()), $cache);

    $repo->all();
    expect($cache->has(CoverageCacheKey::zonesAll()))->toBeTrue();

    $repo->save(sampleZone('Zona Norte'));
    expect($cache->has(CoverageCacheKey::zonesAll()))->toBeFalse();
});
