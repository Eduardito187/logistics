<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Logistics\Planning\Domain\Contract\OptimizedStop;
use Logistics\Planning\Domain\Contract\RouteStop;
use Logistics\Planning\Infrastructure\Routing\OsrmRouteOptimizer;

/**
 * Paradas de prueba sobre un meridiano (misma latitud, longitud creciente): el CD en 0.00, y luego a
 * 0.02, 0.01 y 0.03. El orden crudo [cd, a, b, c] NO es el del vecino más cercano, que encadena
 * cd → b (0.01) → a (0.02) → c (0.03).
 *
 * @return list<RouteStop>
 */
function osrmStops(): array
{
    return [
        RouteStop::of('cd', 0.0, 0.0),
        RouteStop::of('a', 0.0, 0.02),
        RouteStop::of('b', 0.0, 0.01),
        RouteStop::of('c', 0.0, 0.03),
    ];
}

/**
 * Ids de los envíos en el orden resuelto por el optimizador.
 *
 * @param  list<OptimizedStop> $optimized
 * @return list<string>
 */
function optimizedOrder(array $optimized): array
{
    return array_map(static fn (OptimizedStop $s): string => $s->shipmentId, $optimized);
}

it('falls back to nearest-neighbour order when OSRM fails', function (): void {
    // OSRM devuelve 500: el optimizador degrada al orden por vecino más cercano, no al orden crudo.
    Http::fake(['*' => Http::response(null, 500)]);

    $optimizer = new OsrmRouteOptimizer('http://osrm.test', 2);
    $result = $optimizer->optimize(new DateTimeImmutable('2026-10-13T07:00:00+00:00'), osrmStops());

    $order = optimizedOrder($result);

    // El fallback reordena por cercanía (cd fijo primero): cd → b → a → c.
    expect($order)->toBe(['cd', 'b', 'a', 'c'])
        // Y no deja el orden crudo de entrada.
        ->and($order)->not->toBe(['cd', 'a', 'b', 'c']);

    // ETA secuencial del fallback: 20 min por tramo (red de seguridad).
    expect($result[0]->eta->minutesFromDeparture)->toBe(20)
        ->and($result[3]->eta->minutesFromDeparture)->toBe(80);
});

it('falls back when OSRM returns a non-Ok code', function (): void {
    Http::fake(['*' => Http::response(['code' => 'NoRoute'], 200)]);

    $optimizer = new OsrmRouteOptimizer('http://osrm.test', 2);
    $result = $optimizer->optimize(new DateTimeImmutable('2026-10-13T07:00:00+00:00'), osrmStops());

    expect(optimizedOrder($result))->toBe(['cd', 'b', 'a', 'c']);
});

it('uses the OSRM /trip order and per-leg durations when OSRM responds Ok', function (): void {
    // /trip devuelve el orden óptimo a, c, b: el waypoint de a va en la posición 0, b en la 2 y c en la 1.
    Http::fake(['*' => Http::response([
        'code' => 'Ok',
        'waypoints' => [
            ['waypoint_index' => 0], // a (índice de entrada 0)
            ['waypoint_index' => 2], // b (índice de entrada 1)
            ['waypoint_index' => 1], // c (índice de entrada 2)
        ],
        'trips' => [
            ['legs' => [
                ['duration' => 600],  // a -> c : 10 min
                ['duration' => 1200], // c -> b : 20 min
            ]],
        ],
    ], 200)]);

    $stops = [
        RouteStop::of('a', 0.0, 0.01),
        RouteStop::of('b', 0.0, 0.02),
        RouteStop::of('c', 0.0, 0.03),
    ];

    $optimizer = new OsrmRouteOptimizer('http://osrm.test', 2);
    $result = $optimizer->optimize(new DateTimeImmutable('2026-10-13T07:00:00+00:00'), $stops);

    expect(optimizedOrder($result))->toBe(['a', 'c', 'b'])
        ->and($result[0]->eta->minutesFromDeparture)->toBe(0)
        ->and($result[1]->eta->minutesFromDeparture)->toBe(10)
        ->and($result[2]->eta->minutesFromDeparture)->toBe(30);
});
