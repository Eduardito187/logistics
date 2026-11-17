<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\Facades\Http;
use Logistics\Execution\Infrastructure\Routing\OsrmStreetGeometry;
use Tests\TestCase;

/**
 * Servicio OSRM de Ejecución: rutea por CALLES las paradas de la ruta cuando no hay plan con geometría, para
 * que la ruta del chofer nunca salga en línea recta por no tener plan. Entra/sale en primitivos [lng,lat] y
 * es tolerante (null ante host vacío, fallo o menos de dos puntos), nunca lanza. Se arranca la app para
 * `Http::fake` y el contenedor.
 */
uses(TestCase::class);

/** Dos paradas reales de Santa Cruz, en pares [lng, lat] (formato GeoJSON). */
const OSG_POINTS = [[-63.1712, -17.7465], [-63.1966, -17.7633]];

/** Host OSRM de prueba (interceptado por Http::fake). */
const OSG_HOST = 'http://osrm.test';

function osgService(string $host): OsrmStreetGeometry
{
    return new OsrmStreetGeometry(app(HttpClientFactory::class), $host, 5);
}

it('rutea por calles: parsea la geometría geojson de OSRM a una LineString primitiva', function (): void {
    Http::fake([
        'osrm.test/*' => Http::response([
            'code' => 'Ok',
            'routes' => [[
                'distance' => 1234.5,
                'duration' => 300.0,
                'geometry' => ['coordinates' => [[-63.1712, -17.7465], [-63.18, -17.755], [-63.1966, -17.7633]]],
            ]],
        ]),
    ]);

    $geometry = osgService(OSG_HOST)->forPoints(OSG_POINTS);

    expect($geometry)->toBe([
        'type' => 'LineString',
        'coordinates' => [[-63.1712, -17.7465], [-63.18, -17.755], [-63.1966, -17.7633]],
        'distance_meters' => 1234.5,
        'duration_seconds' => 300.0,
    ]);
});

it('devuelve null con el host vacío (OSRM apagado) sin pegarle a la red', function (): void {
    Http::fake(['*' => Http::response('no debería llamarse', 500)]);

    expect(osgService('')->forPoints(OSG_POINTS))->toBeNull();

    Http::assertNothingSent();
});

it('devuelve null (y no lanza) ante un fallo del ruteador o una respuesta no-Ok', function (): void {
    Http::fake(['osrm.test/*' => Http::response('boom', 500)]);
    expect(osgService(OSG_HOST)->forPoints(OSG_POINTS))->toBeNull();

    Http::fake(['osrm.test/*' => Http::response(['code' => 'NoRoute', 'routes' => []])]);
    expect(osgService(OSG_HOST)->forPoints(OSG_POINTS))->toBeNull();
});

it('devuelve null con menos de dos puntos', function (): void {
    expect(osgService(OSG_HOST)->forPoints([[-63.1712, -17.7465]]))->toBeNull();
});
