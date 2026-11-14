<?php

declare(strict_types=1);

use App\Metrics\MetricsCollector;
use App\Observability\Heartbeat;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeMetricsRedis;

// Collector de métricas (Ola 5): expone las series RED/USE leídas de Valkey y de PostgreSQL, y degrada
// a valores neutros —sin romper el scrape— cuando falta una dependencia.

beforeEach(function (): void {
    config()->set('observability.metrics.store.prefix', 'obs:');
    config()->set('observability.metrics.queues', ['outbox' => 'outbox', 'sap' => 'sap']);
});

it('degrades to neutral values when Valkey is down and the tables are unreachable', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey down'));

    // Una conexión de base inexistente hace fallar TODAS las sondas SQL (equivale a tablas ausentes) sin
    // abortar la transacción del test. Se restaura al final para que el teardown pueda hacer rollback.
    $default = config('database.default');
    config()->set('database.default', 'observability_missing_connection');

    try {
        $body = app(MetricsCollector::class)->render();
    } finally {
        config()->set('database.default', $default);
    }

    expect($body)->toContain('logistics_up 1')
        ->and($body)->toContain('# TYPE logistics_http_requests_total counter')
        ->and($body)->toContain('# TYPE logistics_http_request_duration_seconds histogram')
        ->and($body)->toContain('# TYPE logistics_span_duration_seconds histogram')
        ->and($body)->toContain('logistics_queue_depth{queue="sap"} 0')
        ->and($body)->toContain('logistics_queue_delayed{queue="outbox"} 0')
        ->and($body)->toContain('logistics_queue_reserved{queue="outbox"} 0')
        // Latidos: -1 = nunca se observó (0 se leería como "recién latió").
        ->and($body)->toContain('logistics_scheduler_last_tick_age_seconds -1')
        ->and($body)->toContain('logistics_queue_worker_last_activity_age_seconds{queue="outbox"} -1')
        ->and($body)->toContain('logistics_outbox_messages{status="pending"} 0')
        ->and($body)->toContain('logistics_outbox_backlog_total 0')
        ->and($body)->toContain('logistics_outbox_oldest_pending_age_seconds 0')
        ->and($body)->toContain('logistics_failed_jobs_total 0')
        ->and($body)->toContain('logistics_db_connections{state="active"} 0')
        ->and($body)->toContain('logistics_db_max_connections 0')
        ->and($body)->toContain('# TYPE logistics_db_replication_lag_bytes gauge')
        ->and($body)->toContain('logistics_cache_hits_total 0')
        ->and($body)->toContain('logistics_cache_misses_total 0')
        ->and($body)->toContain('logistics_cache_memory_bytes 0');
});

it('exposes the HTTP, span, queue, heartbeat and cache series read from Valkey', function (): void {
    $series = 'api/v1/app/orders/{order}|GET|app';

    FakeMetricsRedis::install(
        hashes: [
            'obs:http:requests' => ['api/v1/app/orders/{order}|GET|200|app' => '7'],
            'obs:http:duration' => [$series.'|le|0.05' => '4', $series.'|le|0.5' => '3', $series.'|sum' => '1.5'],
            'obs:span:duration' => ['outbox.pump|le|1' => '2', 'outbox.pump|sum' => '1.2'],
            'obs:heartbeat' => [
                Heartbeat::SCHEDULER => (string) (time() - 30),
                Heartbeat::WORKER_PREFIX.'outbox' => (string) (time() - 5),
            ],
        ],
        // Por cola, en orden de config: LLEN, ZCARD delayed, ZCARD reserved.
        pipelineResult: [3, 1, 2, 0, 0, 4],
        info: ['keyspace_hits' => '90', 'keyspace_misses' => '10', 'used_memory' => '2048'],
    );

    $body = app(MetricsCollector::class)->render();
    $labels = 'route="api/v1/app/orders/{order}",method="GET",gate="app"';

    expect($body)
        ->toContain('logistics_http_requests_total{route="api/v1/app/orders/{order}",method="GET",status="200",gate="app"} 7')
        ->toContain('logistics_http_request_duration_seconds_bucket{'.$labels.',le="0.05"} 4')
        ->toContain('logistics_http_request_duration_seconds_bucket{'.$labels.',le="0.25"} 4')
        ->toContain('logistics_http_request_duration_seconds_bucket{'.$labels.',le="0.5"} 7')
        ->toContain('logistics_http_request_duration_seconds_bucket{'.$labels.',le="+Inf"} 7')
        ->toContain('logistics_http_request_duration_seconds_sum{'.$labels.'} 1.5')
        ->toContain('logistics_http_request_duration_seconds_count{'.$labels.'} 7')
        ->toContain('logistics_span_duration_seconds_bucket{span="outbox.pump",le="1"} 2')
        ->toContain('logistics_span_duration_seconds_count{span="outbox.pump"} 2')
        ->toContain('logistics_queue_depth{queue="outbox"} 3')
        ->toContain('logistics_queue_delayed{queue="outbox"} 1')
        ->toContain('logistics_queue_reserved{queue="outbox"} 2')
        ->toContain('logistics_queue_reserved{queue="sap"} 4')
        ->toContain('logistics_queue_worker_last_activity_age_seconds{queue="sap"} -1')
        ->toContain('logistics_cache_hits_total 90')
        ->toContain('logistics_cache_misses_total 10')
        ->toContain('logistics_cache_memory_bytes 2048')
        ->toMatch('/logistics_scheduler_last_tick_age_seconds 3[0-2]\n/')
        ->toMatch('/logistics_queue_worker_last_activity_age_seconds\{queue="outbox"\} [5-7]\n/');
});

it('writes the scheduler and worker heartbeats to Valkey', function (): void {
    config()->set('observability.metrics.heartbeat.enabled', true);
    config()->set('observability.metrics.heartbeat.worker_interval', 1);
    $redis = FakeMetricsRedis::install();

    Heartbeat::scheduler();
    Heartbeat::worker('outbox,notifications');

    $fields = array_map(static fn (array $call): string => $call[0].' '.$call[1].' '.$call[2], $redis->calls);

    expect($fields)->toBe([
        'hset obs:heartbeat scheduler',
        'hset obs:heartbeat worker:outbox',
        'hset obs:heartbeat worker:notifications',
    ]);
});

it('swallows a Valkey failure while writing a heartbeat', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey down'));

    Heartbeat::scheduler();

    expect(true)->toBeTrue();
});
