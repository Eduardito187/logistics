<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Infrastructure\Cache\CachingGeoAreaRepository;
use Logistics\Coverage\Infrastructure\Cache\CoverageCacheKey;

// Nombre de área de ejemplo reutilizado en el sembrado y las aserciones.
const SANTA_CRUZ = 'Santa Cruz';

function fakeGeoAreaRepository(GeoArea ...$areas): GeoAreaRepository
{
    return new class(array_values($areas)) implements GeoAreaRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        /** @param list<GeoArea> $areas */
        public function __construct(private array $areas) {}

        public function nextIdentity(): GeoAreaId
        {
            return GeoAreaId::generate();
        }

        public function save(GeoArea $area): void
        {
            $this->saves++;
            $this->areas[] = $area;
        }

        public function findById(GeoAreaId $id): ?GeoArea
        {
            return null;
        }

        public function findByNameAndLevel(string $name, AdminLevel $level): ?GeoArea
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->areas;
        }
    };
}

function sampleGeoArea(string $name = SANTA_CRUZ, AdminLevel $level = AdminLevel::Department): GeoArea
{
    return GeoArea::create(GeoAreaId::generate(), $name, $level, null);
}

it('serves the second all() from cache and rehydrates each area', function (): void {
    $inner = fakeGeoAreaRepository(sampleGeoArea(SANTA_CRUZ), sampleGeoArea('Warnes', AdminLevel::Municipality));
    $repo = new CachingGeoAreaRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(GeoArea::class)
        ->and($second[0]->name())->toBe(SANTA_CRUZ)
        ->and($second[0]->level())->toBe(AdminLevel::Department)
        ->and($second[1]->level())->toBe(AdminLevel::Municipality);
});

it('stores the primitive array of each area, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingGeoAreaRepository(fakeGeoAreaRepository(sampleGeoArea()), $cache);

    $repo->all();

    $stored = $cache->get(CoverageCacheKey::geoAreasAll());
    expect($stored)->toBeArray()
        ->and($stored[0]['name'])->toBe(SANTA_CRUZ)
        ->and($stored[0]['level'])->toBe('department')
        ->and($stored[0]['parentId'])->toBeNull();
});

it('invalidates both the areas list and the derived municipalities view on save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingGeoAreaRepository(fakeGeoAreaRepository(sampleGeoArea()), $cache);

    $repo->all();
    $cache->put(CoverageCacheKey::municipalitiesAll(), [['id' => 'x']], 600);
    expect($cache->has(CoverageCacheKey::geoAreasAll()))->toBeTrue()
        ->and($cache->has(CoverageCacheKey::municipalitiesAll()))->toBeTrue();

    $repo->save(sampleGeoArea('Warnes', AdminLevel::Municipality));

    expect($cache->has(CoverageCacheKey::geoAreasAll()))->toBeFalse()
        ->and($cache->has(CoverageCacheKey::municipalitiesAll()))->toBeFalse();
});
