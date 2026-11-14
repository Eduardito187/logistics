<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeMetricsRedis;

// Middleware de métricas HTTP (Ola 5): registra contador + histograma en Valkey con UNA ida y vuelta,
// usa el patrón de la ruta (nunca el path crudo), y tolera Valkey caído sin romper el request.
// Valkey se reemplaza por un doble: los tests no dependen de un Valkey real ni escriben en él.

beforeEach(function (): void {
    // En testing la instrumentación nace apagada: se enciende solo para estos tests.
    config()->set('observability.metrics.http.enabled', true);
    config()->set('observability.metrics.http.sample_rate', 1.0);
    config()->set('observability.metrics.store.connection', 'default');
    config()->set('observability.metrics.store.prefix', 'obs:');

    Route::get('/_obs/items/{id}', static fn (): string => 'ok');
});

it('records the request counter and the duration histogram in a single round trip', function (): void {
    $redis = FakeMetricsRedis::install();

    $this->get('/_obs/items/123456')->assertOk();

    expect($redis->pipelines)->toBe(1)
        ->and($redis->calls)->toHaveCount(3);

    [$requests, $bucket, $sum] = $redis->calls;

    // Etiqueta `route` = patrón de la ruta, no el path con el id.
    expect($requests)->toBe(['hincrby', 'obs:http:requests', '_obs/items/{id}|GET|200|other', 1])
        ->and($bucket[0])->toBe('hincrby')
        ->and($bucket[1])->toBe('obs:http:duration')
        ->and($bucket[2])->toStartWith('_obs/items/{id}|GET|other|le|')
        ->and($bucket[3])->toBe(1)
        ->and($sum[0])->toBe('hincrbyfloat')
        ->and($sum[1])->toBe('obs:http:duration')
        ->and($sum[2])->toBe('_obs/items/{id}|GET|other|sum')
        ->and($sum[3])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    expect(json_encode($redis->calls))->not->toContain('123456');
});

it('labels a request without a matching route as unmatched', function (): void {
    $redis = FakeMetricsRedis::install();

    $this->getJson('/api/_obs/does-not-exist/987')->assertNotFound();

    expect($redis->calls[0])->toBe(['hincrby', 'obs:http:requests', 'unmatched|GET|404|public', 1]);
});

it('never breaks the request when Valkey is down', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey down'));

    $this->get('/_obs/items/1')->assertOk()->assertSee('ok');
});

it('does not touch Valkey when HTTP metrics are disabled', function (): void {
    config()->set('observability.metrics.http.enabled', false);
    Redis::shouldReceive('connection')->never();

    $this->get('/_obs/items/1')->assertOk();
});

it('does not touch Valkey when the sample rate is zero', function (): void {
    config()->set('observability.metrics.http.sample_rate', 0.0);
    Redis::shouldReceive('connection')->never();

    $this->get('/_obs/items/1')->assertOk();
});

it('weights a sampled request by the inverse of the sample rate', function (): void {
    // El sorteo es aleatorio: se repite el request hasta que uno salga sorteado (60 intentos al 50 %).
    config()->set('observability.metrics.http.sample_rate', 0.5);
    $redis = FakeMetricsRedis::install();

    for ($attempt = 0; $attempt < 60 && $redis->calls === []; $attempt++) {
        $this->get('/_obs/items/1')->assertOk();
    }

    // Tasa 0.5 => cada muestra tomada cuenta por 2.
    expect($redis->calls[0][3])->toBe(2);
});

it('excludes the metrics endpoint itself', function (): void {
    // Sin token ni allowlist el endpoint responde 404 sin consultar nada: Valkey no debe tocarse.
    config()->set('observability.metrics.token', null);
    config()->set('observability.metrics.ip_allowlist', []);
    Redis::shouldReceive('connection')->never();

    $this->get('/api/metrics')->assertNotFound();
});
