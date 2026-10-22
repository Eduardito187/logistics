<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Infrastructure\Cache\CachingMaterialMappingRepository;
use Logistics\SapConnector\Infrastructure\Cache\SapConnectorCacheKey;

function fakeMaterialMappingRepository(MaterialMapping ...$mappings): MaterialMappingRepository
{
    return new class(array_values($mappings)) implements MaterialMappingRepository
    {
        public int $allCalls = 0;

        public int $saves = 0;

        /** @param list<MaterialMapping> $mappings */
        public function __construct(private array $mappings) {}

        public function nextIdentity(): MaterialMappingId
        {
            return MaterialMappingId::generate();
        }

        public function save(MaterialMapping $mapping): void
        {
            $this->saves++;
            $this->mappings[] = $mapping;
        }

        public function findBySku(string $sku): ?MaterialMapping
        {
            return null;
        }

        public function findByMaterial(SapMaterial $material): ?MaterialMapping
        {
            return null;
        }

        public function all(): array
        {
            $this->allCalls++;

            return $this->mappings;
        }
    };
}

function sampleMapping(string $sku = 'SKU-1', ?string $sellerId = null): MaterialMapping
{
    return MaterialMapping::reconstitute(
        MaterialMappingId::generate(),
        $sku,
        SapMaterial::of('MAT-100'),
        SapCenter::of('C001'),
        SapWarehouse::of('W01'),
        $sellerId,
    );
}

it('serves the second all() from cache and rehydrates each mapping', function (): void {
    $inner = fakeMaterialMappingRepository(sampleMapping('SKU-1'), sampleMapping('SKU-2', 'seller-9'));
    $repo = new CachingMaterialMappingRepository($inner, new CacheRepository(new ArrayStore));

    $first = $repo->all();
    $second = $repo->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(MaterialMapping::class)
        ->and($second[0]->sku())->toBe('SKU-1')
        ->and($second[0]->material()->value)->toBe('MAT-100')
        ->and($second[0]->sellerId())->toBeNull()
        ->and($second[1]->sellerId())->toBe('seller-9');
});

it('stores the primitive array of each mapping, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingMaterialMappingRepository(fakeMaterialMappingRepository(sampleMapping('SKU-1')), $cache);

    $repo->all();

    $stored = $cache->get(SapConnectorCacheKey::materialMappingsAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['sku'])->toBe('SKU-1')
        ->and($stored[0]['material'])->toBe('MAT-100')
        ->and($stored[0]['center'])->toBe('C001')
        ->and($stored[0]['warehouse'])->toBe('W01')
        ->and($stored[0]['sellerId'])->toBeNull();
});

it('invalidates the cached list on save', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $repo = new CachingMaterialMappingRepository(fakeMaterialMappingRepository(sampleMapping()), $cache);

    $repo->all();
    expect($cache->has(SapConnectorCacheKey::materialMappingsAll()))->toBeTrue();

    $repo->save(sampleMapping('SKU-2'));
    expect($cache->has(SapConnectorCacheKey::materialMappingsAll()))->toBeFalse();
});
