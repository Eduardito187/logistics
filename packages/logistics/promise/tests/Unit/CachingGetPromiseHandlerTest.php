<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Promise\Application\Query\GetPromise\GetPromise;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Infrastructure\Cache\CachingGetPromiseHandler;
use Logistics\Promise\Infrastructure\Cache\PromiseViewCacheKey;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Handler interno falso: devuelve una vista fija y cuenta cuántas veces se lo invoca.
 *
 * @implements QueryHandler<GetPromise, ?PromiseView>
 */
function countingGetPromiseHandler(?PromiseView $view): object
{
    return new class($view) implements QueryHandler
    {
        public int $calls = 0;

        public function __construct(private readonly ?PromiseView $view) {}

        public function handle(Query $query): ?PromiseView
        {
            $this->calls++;

            return $this->view;
        }
    };
}

function samplePromiseView(string $id): PromiseView
{
    return new PromiseView(
        $id,
        'ORD-1',
        'zone-1',
        'Zona Sur',
        '2026-10-20',
        '09:00-13:00',
        'reserved',
        '2026-10-20T12:00:00+00:00',
    );
}

it('caches the found view and serves the second call without hitting the inner handler', function (): void {
    $inner = countingGetPromiseHandler(samplePromiseView('p-1'));
    $handler = new CachingGetPromiseHandler($inner, new CacheRepository(new ArrayStore));
    $query = new GetPromise('p-1');

    $first = $handler->handle($query);
    $second = $handler->handle($query);

    expect($inner->calls)->toBe(1)
        ->and($first)->toBeInstanceOf(PromiseView::class)
        ->and($second->id)->toBe('p-1')
        ->and($second->zoneName)->toBe('Zona Sur')
        ->and($second->status)->toBe('reserved');
});

it('stores the primitive array of the view, never the object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetPromiseHandler(countingGetPromiseHandler(samplePromiseView('p-1')), $cache);

    $handler->handle(new GetPromise('p-1'));

    $stored = $cache->get(PromiseViewCacheKey::forId('p-1'));
    expect($stored)->toBeArray()
        ->and($stored['id'])->toBe('p-1')
        ->and($stored['zone_name'])->toBe('Zona Sur')
        ->and($stored['reserved_until'])->toBe('2026-10-20T12:00:00+00:00');
});

it('does not cache a missing promise', function (): void {
    $inner = countingGetPromiseHandler(null);
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetPromiseHandler($inner, $cache);

    $result = $handler->handle(new GetPromise('missing'));

    expect($result)->toBeNull()
        ->and($cache->has(PromiseViewCacheKey::forId('missing')))->toBeFalse();
});

it('rebuilds a view with null optionals from its primitive form', function (): void {
    $view = new PromiseView('p-2', 'ORD-2', 'zone-9', null, '2026-10-21', 'PM', 'confirmed', null);
    $cache = new CacheRepository(new ArrayStore);
    $handler = new CachingGetPromiseHandler(countingGetPromiseHandler($view), $cache);
    $query = new GetPromise('p-2');

    $handler->handle($query);
    $cached = $handler->handle($query);

    expect($cached->zoneName)->toBeNull()
        ->and($cached->reservedUntil)->toBeNull()
        ->and($cached->status)->toBe('confirmed');
});
