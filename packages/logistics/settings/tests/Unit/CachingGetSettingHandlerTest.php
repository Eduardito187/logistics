<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Settings\Infrastructure\Cache\CachingGetSettingHandler;
use Logistics\Settings\Infrastructure\Cache\SettingsCacheKey;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Handler interno falso: devuelve un valor fijo y cuenta cuántas veces se lo invoca.
 *
 * @param array<string, mixed>|null $value
 *
 * @implements QueryHandler<GetSettingQuery, array<string, mixed>|null>
 */
function countingGetSettingHandler(?array $value): object
{
    return new class($value) implements QueryHandler
    {
        public int $calls = 0;

        /** @param array<string, mixed>|null $value */
        public function __construct(private readonly ?array $value) {}

        public function handle(Query $query): ?array
        {
            $this->calls++;

            return $this->value;
        }
    };
}

it('caches the "now" value and serves the second call without hitting the inner handler', function (): void {
    $inner = countingGetSettingHandler(['lead_days' => 2]);
    $handler = new CachingGetSettingHandler($inner, new CacheRepository(new ArrayStore));
    $query = new GetSettingQuery('promise.lead_days');

    $first = $handler->handle($query);
    $second = $handler->handle($query);

    expect($inner->calls)->toBe(1)
        ->and($first)->toBe(['lead_days' => 2])
        ->and($second)->toBe(['lead_days' => 2]);
});

it('stores the primitive value array as-is (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetSettingHandler(countingGetSettingHandler(['lead_days' => 2]), $cache);

    $handler->handle(new GetSettingQuery('promise.lead_days'));

    expect($cache->get(SettingsCacheKey::currentValue('promise.lead_days')))->toBe(['lead_days' => 2]);
});

it('does not cache historical reads (at present) and always delegates them', function (): void {
    $inner = countingGetSettingHandler(['lead_days' => 2]);
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetSettingHandler($inner, $cache);
    $query = new GetSettingQuery('promise.lead_days', '2026-01-01T00:00:00+00:00');

    $handler->handle($query);
    $handler->handle($query);

    expect($inner->calls)->toBe(2)
        ->and($cache->has(SettingsCacheKey::currentValue('promise.lead_days')))->toBeFalse();
});

it('does not cache a missing key', function (): void {
    $inner = countingGetSettingHandler(null);
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetSettingHandler($inner, $cache);

    $result = $handler->handle(new GetSettingQuery('promise.missing'));

    expect($result)->toBeNull()
        ->and($cache->has(SettingsCacheKey::currentValue('promise.missing')))->toBeFalse();
});
