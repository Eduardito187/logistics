<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Application\Command\ProjectExecutionEvent\ProjectExecutionEvent;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Application\Query\GetPublicLinkState\GetPublicLinkState;
use Logistics\Tracking\Application\Query\GetPublicTracking\GetPublicTracking;
use Logistics\Tracking\Application\Query\GetPublicTrackingSnapshot\GetPublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicLinkState;
use Logistics\Tracking\Application\ReadModel\PublicTrackingSnapshot;
use Logistics\Tracking\Application\ReadModel\PublicTrackingView;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Infrastructure\ReadSide\PublicTrackingCache;

/**
 * Read-side PÚBLICO del seguimiento (ola 6): caché de lectura por token con invalidación por evento y
 * cabeceras para el caché de borde. Ver docs/perf/tracking-read-side.md.
 *
 * El store de caché en tests es `array` (phpunit.xml): se comporta como Valkey para estas garantías y
 * respeta el reloj de `travel()`. Corre contra logistics_test.
 */
beforeEach(function (): void {
    // Nada que preparar: cada test arma su seguimiento. El `skip` de abajo aplica a los tests del archivo.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Abre el seguimiento de un pedido por el comando real y devuelve su token público. */
function readCacheOpenTracking(string $orderReference): string
{
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $orderReference));

    return (string) TrackingRecord::query()->where('order_reference', $orderReference)->value('token');
}

/**
 * Lleva un pedido hasta "en camino" por los comandos reales.
 *
 * @return array{token: string, shipment: string}
 */
function readCacheTrackingOnTheWay(string $orderReference): array
{
    $bus = app(CommandBus::class);
    $shipment = Uuid::generate()->value;
    $token = readCacheOpenTracking($orderReference);

    $bus->dispatch(new BindShipmentToTracking(orderReference: $orderReference, shipmentId: $shipment));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $orderReference));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $orderReference));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_loaded', $shipment));
    $bus->dispatch(new ProjectExecutionEvent('execution.shipment_departed', $shipment));

    return ['token' => $token, 'shipment' => $shipment];
}

/** Foto pública del token, pedida por el bus como lo hace Presentation. */
function readCacheSnapshot(string $token): PublicTrackingSnapshot
{
    $snapshot = app(QueryBus::class)->ask(new GetPublicTrackingSnapshot($token));
    assert($snapshot instanceof PublicTrackingSnapshot);

    return $snapshot;
}

/** Cantidad de consultas SQL que ejecuta el callback (cualquier tabla, cualquier conexión por defecto). */
function readCacheQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

it('serves the second read of the same token without touching the database', function (): void {
    $token = readCacheOpenTracking('order-read-cache-1');

    $first = readCacheQueryCount(fn () => readCacheSnapshot($token));
    $second = readCacheQueryCount(fn () => readCacheSnapshot($token));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(readCacheSnapshot($token)->view?->status)->toBe(VisibleStatus::Pending->value);
});

it('answers the legacy public queries from the same cached snapshot', function (): void {
    $token = readCacheOpenTracking('order-read-cache-2');
    readCacheSnapshot($token);

    $queries = readCacheQueryCount(function () use ($token): void {
        $bus = app(QueryBus::class);

        expect($bus->ask(new GetPublicTracking($token)))->toBeInstanceOf(PublicTrackingView::class)
            ->and($bus->ask(new GetPublicLinkState($token)))->toBe(PublicLinkState::Active);
    });

    expect($queries)->toBe(0);
});

it('invalidates on a status change so the next read reflects the new state', function (): void {
    $token = readCacheOpenTracking('order-read-cache-3');
    $before = readCacheSnapshot($token);

    app(CommandBus::class)->dispatch(
        new AdvanceTracking(status: VisibleStatus::Processing, orderReference: 'order-read-cache-3'),
    );

    $after = readCacheSnapshot($token);

    expect($before->view?->status)->toBe(VisibleStatus::Pending->value)
        ->and($after->view?->status)->toBe(VisibleStatus::Processing->value)
        ->and($after->etag)->not->toBe($before->etag);
});

it('keeps serving the cached snapshot when a write bypasses the repository until the ttl expires', function (): void {
    $token = readCacheOpenTracking('order-read-cache-4');
    readCacheSnapshot($token);

    // Escritura por fuera del repositorio (seeder, SQL manual): no hay invalidación, manda el TTL.
    TrackingRecord::query()->where('token', $token)->update(['remaining_stops' => 7]);

    expect(readCacheSnapshot($token)->view?->remainingStops)->toBe(0);

    $this->travel((int) config('logistics-tracking.public_cache.ttl_stale') + 1)->seconds();

    expect(readCacheSnapshot($token)->view?->remainingStops)->toBe(7);
});

it('does not invalidate on a truck position update: the fresh ttl covers position and eta', function (): void {
    ['token' => $token, 'shipment' => $shipment] = readCacheTrackingOnTheWay('order-read-cache-5');
    readCacheSnapshot($token);

    app(CommandBus::class)->dispatch(
        new UpdateTruckPosition($shipment, -17.7833, -63.1821, now()->toIso8601String()),
    );

    $queries = readCacheQueryCount(function () use ($token): void {
        expect(readCacheSnapshot($token)->view?->lastPosition)->toBeNull();
    });

    expect($queries)->toBe(0);

    $this->travel((int) config('logistics-tracking.public_cache.ttl_stale') + 1)->seconds();

    expect(readCacheSnapshot($token)->view?->lastPosition)->not->toBeNull();
});

it('caches unknown tokens briefly so a token sweep does not hit the database', function (): void {
    $token = TrackingToken::generate()->toString();

    $first = readCacheQueryCount(fn () => readCacheSnapshot($token));
    $second = readCacheQueryCount(fn () => readCacheSnapshot($token));

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe(0)
        ->and(readCacheSnapshot($token)->state)->toBe(PublicLinkState::NotFound);

    // Aparece por fuera del repositorio: el negativo aguanta solo su TTL corto.
    TrackingRecord::factory()->withStatus(VisibleStatus::OnTheWay)->create(['token' => $token]);

    expect(readCacheSnapshot($token)->state)->toBe(PublicLinkState::NotFound);

    $this->travel((int) config('logistics-tracking.public_cache.ttl_negative') + 1)->seconds();

    expect(readCacheSnapshot($token)->state)->toBe(PublicLinkState::Active);
});

it('drops the negative entry as soon as the tracking is saved through the repository', function (): void {
    $token = TrackingToken::generate();

    expect(readCacheSnapshot($token->toString())->state)->toBe(PublicLinkState::NotFound);

    app(TrackingRepository::class)->save(
        ShipmentTracking::open(TrackingId::generate(), 'order-read-cache-6', $token),
    );

    expect(readCacheSnapshot($token->toString())->state)->toBe(PublicLinkState::Active);
});

it('never queries nor caches a token with an invalid shape', function (): void {
    $queries = readCacheQueryCount(function (): void {
        expect(readCacheSnapshot('nope')->state)->toBe(PublicLinkState::NotFound);
    });

    expect($queries)->toBe(0)
        ->and(app(PublicTrackingCache::class)->peek('nope'))->toBeNull();
});

it('reads from the database on every request when the cache is disabled', function (): void {
    config(['logistics-tracking.public_cache.enabled' => false]);
    app()->forgetInstance(PublicTrackingCache::class);

    $token = readCacheOpenTracking('order-read-cache-7');
    readCacheSnapshot($token);

    expect(readCacheQueryCount(fn () => readCacheSnapshot($token)))->toBeGreaterThan(0);
});

it('sends public cache headers and an etag on the public json route', function (): void {
    $this->withoutMiddleware();
    $token = readCacheOpenTracking('order-read-cache-8');

    $response = $this->getJson("/api/v1/channels/tracking/{$token}")->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('public')
        ->toContain('max-age=20')
        ->toContain('stale-while-revalidate=30')
        ->and($response->headers->get('ETag'))->toBe('"'.readCacheSnapshot($token)->etag.'"');
});

it('answers 304 with an empty body when if-none-match matches, and 200 again after a change', function (): void {
    $this->withoutMiddleware();
    $token = readCacheOpenTracking('order-read-cache-9');
    $etag = (string) $this->getJson("/api/v1/channels/tracking/{$token}")->headers->get('ETag');

    $notModified = $this->getJson("/api/v1/channels/tracking/{$token}", ['If-None-Match' => $etag]);

    expect($notModified->getStatusCode())->toBe(304)
        ->and((string) $notModified->getContent())->toBe('');

    app(CommandBus::class)->dispatch(
        new AdvanceTracking(status: VisibleStatus::Processing, orderReference: 'order-read-cache-9'),
    );

    $this->getJson("/api/v1/channels/tracking/{$token}", ['If-None-Match' => $etag])
        ->assertOk()
        ->assertJsonPath('data.status', VisibleStatus::Processing->value);
});

it('does not mark error responses nor credentialed requests as public', function (): void {
    $this->withoutMiddleware();
    $token = readCacheOpenTracking('order-read-cache-10');

    $notFound = $this->getJson('/api/v1/channels/tracking/'.TrackingToken::generate()->toString())
        ->assertStatus(404);
    $withAuthorization = $this->getJson("/api/v1/channels/tracking/{$token}", ['Authorization' => 'Bearer x'])
        ->assertOk();

    expect((string) $notFound->headers->get('Cache-Control'))->not->toContain('public')
        ->and($notFound->headers->has('ETag'))->toBeFalse()
        ->and((string) $withAuthorization->headers->get('Cache-Control'))->not->toContain('public')
        ->and($withAuthorization->headers->has('ETag'))->toBeTrue();
});

it('never sends public cache headers on the authenticated shipment tracking route', function (): void {
    $this->withoutMiddleware();
    ['shipment' => $shipment] = readCacheTrackingOnTheWay('order-read-cache-11');

    $response = $this->getJson("/api/v1/app/tracking/shipments/{$shipment}")->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('public')
        ->and((string) $response->headers->get('Cache-Control'))->not->toContain('stale-while-revalidate');
});

it('serves the public html page without session cookies and with public cache headers', function (): void {
    $token = readCacheOpenTracking('order-read-cache-12');

    $response = $this->get("/t/{$token}")->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');
    $etag = (string) $response->headers->get('ETag');

    expect($cacheControl)->toContain('public')
        ->toContain('max-age=20')
        ->and($etag)->not->toBe('')
        ->and($response->headers->getCookies())->toBe([])
        ->and(Route::getRoutes()->getByName('tracking.public.page')?->gatherMiddleware())->not->toContain('web');

    expect($this->get("/t/{$token}", ['If-None-Match' => $etag])->getStatusCode())->toBe(304);
});
