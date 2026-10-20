<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Coverage\Infrastructure\Cache\CachingMunicipalityCatalog;
use Logistics\Coverage\Infrastructure\Cache\CoverageCacheKey;

function fakeMunicipalityCatalog(MunicipalityView ...$views): MunicipalityCatalog
{
    return new class(array_values($views)) implements MunicipalityCatalog
    {
        public int $allCalls = 0;

        /** @param list<MunicipalityView> $views */
        public function __construct(private array $views) {}

        public function all(): array
        {
            $this->allCalls++;

            return $this->views;
        }
    };
}

it('serves the second all() from cache and rehydrates the view with its derived label', function (): void {
    $inner = fakeMunicipalityCatalog(
        new MunicipalityView('m-1', 'Warnes', 'd-1', 'Santa Cruz'),
        new MunicipalityView('m-2', 'Sucre', null, null),
    );
    $catalog = new CachingMunicipalityCatalog($inner, new CacheRepository(new ArrayStore));

    $first = $catalog->all();
    $second = $catalog->all();

    expect($inner->allCalls)->toBe(1)
        ->and($first)->toHaveCount(2)
        ->and($second[0])->toBeInstanceOf(MunicipalityView::class)
        ->and($second[0]->name)->toBe('Warnes')
        ->and($second[0]->departmentName)->toBe('Santa Cruz')
        ->and($second[0]->label())->toBe('Warnes — Santa Cruz')
        ->and($second[1]->departmentId)->toBeNull()
        ->and($second[1]->label())->toBe('Sucre');
});

it('stores the primitive array of each municipality, never the read model (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $catalog = new CachingMunicipalityCatalog(
        fakeMunicipalityCatalog(new MunicipalityView('m-1', 'Warnes', 'd-1', 'Santa Cruz')),
        $cache,
    );

    $catalog->all();

    $stored = $cache->get(CoverageCacheKey::municipalitiesAll());
    expect($stored)->toBeArray()
        ->and($stored[0])->toBeArray()
        ->and($stored[0]['id'])->toBe('m-1')
        ->and($stored[0]['label'])->toBe('Warnes — Santa Cruz');
});
