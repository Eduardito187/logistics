<?php

declare(strict_types=1);

use App\Observability\Span;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeMetricsRedis;

// Span (Ola 5): cronometra un bloque sin alterar su resultado ni sus excepciones, alimenta el histograma
// en Valkey, deja un log estructurado con el request_id y nunca rompe al bloque si la telemetría falla.

beforeEach(function (): void {
    // En testing los spans nacen apagados: se encienden solo para estos tests.
    config()->set('observability.spans.enabled', true);
    config()->set('observability.spans.log_channel', null);
    config()->set('observability.spans.log_level', 'info');
    config()->set('observability.spans.log_threshold_ms', 0);
    config()->set('observability.metrics.store.prefix', 'obs:');
});

afterEach(function (): void {
    Span::traceWith(null);
});

it('returns the block result and feeds the span histogram in one round trip', function (): void {
    $redis = FakeMetricsRedis::install();

    $result = Span::measure('demo.block', static fn (): int => 42);

    expect($result)->toBe(42)
        ->and($redis->pipelines)->toBe(1)
        ->and($redis->calls[0][0])->toBe('hincrby')
        ->and($redis->calls[0][1])->toBe('obs:span:duration')
        ->and($redis->calls[0][2])->toStartWith('demo.block|le|')
        ->and($redis->calls[1][0])->toBe('hincrbyfloat')
        ->and($redis->calls[1][2])->toBe('demo.block|sum');
});

it('logs the span with the request id from the Context', function (): void {
    FakeMetricsRedis::install();
    Context::add('request_id', 'trace-42');
    Log::spy();

    Span::measure('demo.block', static fn (): string => 'done');

    Log::shouldHaveReceived('log')
        ->withArgs(static fn (string $level, string $message, array $context): bool => $level === 'info'
            && $message === 'span'
            && $context['span'] === 'demo.block'
            && $context['outcome'] === 'ok'
            && $context['request_id'] === 'trace-42'
            && is_float($context['duration_ms']))
        ->once();
});

it('skips the log line for spans below the threshold', function (): void {
    config()->set('observability.spans.log_threshold_ms', 60000);
    FakeMetricsRedis::install();
    Log::spy();

    Span::measure('demo.block', static fn (): string => 'done');

    Log::shouldNotHaveReceived('log');
});

it('rethrows the block exception and still records the span', function (): void {
    $redis = FakeMetricsRedis::install();

    expect(static fn () => Span::measure('demo.failing', static function (): never {
        throw new DomainException('boom');
    }))->toThrow(DomainException::class, 'boom');

    expect($redis->pipelines)->toBe(1);
});

it('never breaks the block when Valkey is down', function (): void {
    Redis::shouldReceive('connection')->andThrow(new RuntimeException('valkey down'));

    expect(Span::measure('demo.block', static fn (): string => 'still works'))->toBe('still works');
});

it('runs the block without measuring when spans are disabled', function (): void {
    config()->set('observability.spans.enabled', false);
    Redis::shouldReceive('connection')->never();

    expect(Span::measure('demo.block', static fn (): int => 7))->toBe(7);
});

it('routes the block through an external tracer when one is plugged in', function (): void {
    FakeMetricsRedis::install();
    $seen = [];

    Span::traceWith(static function (string $name, Closure $callback) use (&$seen): mixed {
        $seen[] = $name;

        return $callback();
    });

    expect(Span::measure('demo.traced', static fn (): string => 'traced'))->toBe('traced')
        ->and($seen)->toBe(['demo.traced']);
});
