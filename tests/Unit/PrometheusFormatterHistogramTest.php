<?php

declare(strict_types=1);

use App\Metrics\PrometheusFormatter;

// Histogramas en el formato de exposición de Prometheus (Ola 5): buckets `le` ACUMULATIVOS, bucket
// `+Inf` con el total, `_sum` y `_count`. El formateador recibe los buckets sin acumular.

it('renders cumulative buckets, +Inf, sum and count for a histogram', function (): void {
    $body = (new PrometheusFormatter)
        ->histogram('demo_duration_seconds', 'Demo.', [0.05, 0.1, 1.0, 2.5], [[
            'labels' => ['route' => 'api/v1/health', 'method' => 'GET'],
            // 3 observaciones <= 0.05, 2 en (0.05, 0.1], 0 en (0.1, 1], 1 en (1, 2.5] y 1 por encima.
            'buckets' => ['0.05' => 3, '0.1' => 2, '2.5' => 1, '+Inf' => 1],
            'sum' => 4.25,
        ]])
        ->render();

    expect($body)->toContain('# HELP demo_duration_seconds Demo.')
        ->and($body)->toContain('# TYPE demo_duration_seconds histogram')
        ->and($body)->toContain('demo_duration_seconds_bucket{route="api/v1/health",method="GET",le="0.05"} 3')
        ->and($body)->toContain('demo_duration_seconds_bucket{route="api/v1/health",method="GET",le="0.1"} 5')
        ->and($body)->toContain('demo_duration_seconds_bucket{route="api/v1/health",method="GET",le="1"} 5')
        ->and($body)->toContain('demo_duration_seconds_bucket{route="api/v1/health",method="GET",le="2.5"} 6')
        ->and($body)->toContain('demo_duration_seconds_bucket{route="api/v1/health",method="GET",le="+Inf"} 7')
        ->and($body)->toContain('demo_duration_seconds_sum{route="api/v1/health",method="GET"} 4.25')
        ->and($body)->toContain('demo_duration_seconds_count{route="api/v1/health",method="GET"} 7')
        ->and($body)->toEndWith("\n");
});

it('keeps the bucket lines in ascending order of le', function (): void {
    $body = (new PrometheusFormatter)
        ->histogram('demo_seconds', 'Demo.', [0.5, 1.0, 10.0], [['buckets' => ['10' => 1], 'sum' => 7]])
        ->render();

    $lines = array_values(array_filter(
        explode("\n", $body),
        static fn (string $line): bool => str_starts_with($line, 'demo_seconds_bucket'),
    ));

    expect($lines)->toBe([
        'demo_seconds_bucket{le="0.5"} 0',
        'demo_seconds_bucket{le="1"} 0',
        'demo_seconds_bucket{le="10"} 1',
        'demo_seconds_bucket{le="+Inf"} 1',
    ]);
});

it('counts observations stored under an unknown bound only in +Inf and _count', function (): void {
    // Datos viejos tras cambiar los límites: no se pierden, entran al total.
    $body = (new PrometheusFormatter)
        ->histogram('demo_seconds', 'Demo.', [1.0], [['buckets' => ['1' => 2, '0.3' => 4], 'sum' => 3.5]])
        ->render();

    expect($body)->toContain('demo_seconds_bucket{le="1"} 2')
        ->and($body)->toContain('demo_seconds_bucket{le="+Inf"} 6')
        ->and($body)->toContain('demo_seconds_count 6')
        ->and($body)->toContain('demo_seconds_sum 3.5');
});

it('renders only HELP and TYPE for a histogram without series', function (): void {
    $body = (new PrometheusFormatter)->histogram('demo_seconds', 'Demo.', [1.0], [])->render();

    expect($body)->toBe("# HELP demo_seconds Demo.\n# TYPE demo_seconds histogram\n");
});

it('uses a canonical le label for each bound', function (): void {
    expect(PrometheusFormatter::boundLabel(0.05))->toBe('0.05')
        ->and(PrometheusFormatter::boundLabel(1.0))->toBe('1')
        ->and(PrometheusFormatter::boundLabel(2.5))->toBe('2.5')
        ->and(PrometheusFormatter::boundLabel(10.0))->toBe('10');
});
