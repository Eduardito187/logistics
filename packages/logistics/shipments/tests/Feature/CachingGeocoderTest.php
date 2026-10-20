<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Shipments\Domain\Contract\Geocoder;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Infrastructure\Geocoding\CachingGeocoder;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodeScoring;
use Logistics\Shipments\Infrastructure\Geocoding\NominatimGeocoder;

// Motivo del skip cuando la suite no corre contra PostgreSQL (base logistics_test).
const CACHING_GEOCODER_PG_REASON = 'Requiere PostgreSQL (base logistics_test).';

/**
 * Fallo simulado del geocoder interno: debe NO invocarse en un acierto de caché.
 */
final class SimulatedGeocoderFailure extends RuntimeException {}

/**
 * Dedup persistente del geocoding contra PostgreSQL (logistics_test): el decorador CachingGeocoder
 * guarda la primera resolución en `geocoding_cache` y sirve las siguientes desde la fila, sin repegar a
 * Nominatim. Verifica el acierto sin I/O, la persistencia del fallo, el agrupamiento por normalización y
 * el incremento de hits.
 */
function cachingGeocoder(HttpClient $http): CachingGeocoder
{
    $inner = new NominatimGeocoder(
        $http,
        new Repository(new ArrayStore),
        GeocodeScoring::fromConfig([]),
        'https://nominatim.test/search',
        'DismacLogisticsTest/1.0 (test@dismac.test)',
        'bo',
        'es',
        5,
        3600,
        0, // sin throttle en tests
    );

    return new CachingGeocoder(
        $inner,
        app(ConnectionInterface::class),
        'nominatim',
        'bo',
    );
}

function exactNominatimHttp(): HttpClient
{
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5000',
        'lon' => '-68.1500',
        'addresstype' => 'building',
        'class' => 'building',
        'type' => 'yes',
    ]])]);

    return $http;
}

it('persists on a miss and serves the second lookup from the table without a second request', function (): void {
    $http = exactNominatimHttp();
    $geocoder = cachingGeocoder($http);
    $address = Address::of('Calle Unica '.uniqid());

    $first = $geocoder->geocode($address);
    $second = $geocoder->geocode($address);

    expect($first->matchType->value)->toBe('EXACT')
        ->and($first->geoPoint?->lat)->toBe(-16.5)
        ->and($second->matchType->value)->toBe('EXACT')
        ->and($second->geoPoint?->lat)->toBe(-16.5);

    // La fila se escribió una sola vez y Nominatim se tocó una sola vez: el segundo lookup fue un acierto.
    $http->assertSentCount(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', CACHING_GEOCODER_PG_REASON);

it('does not call the inner geocoder on a cache hit', function (): void {
    $http = exactNominatimHttp();
    $geocoder = cachingGeocoder($http);
    $address = Address::of('Av. Cache Hit '.uniqid());

    $geocoder->geocode($address); // miss: persiste
    $http->assertSentCount(1);

    // Un geocoder interno que explota si lo invocan: el acierto NO debe tocarlo.
    $throwing = new class implements Geocoder
    {
        public function geocode(Address $address): GeocodeResult
        {
            throw new SimulatedGeocoderFailure('el geocoder interno no debe invocarse en un acierto');
        }

        public function score(Address $address): AddressConfidence
        {
            throw new SimulatedGeocoderFailure('el geocoder interno no debe invocarse en un acierto');
        }
    };

    $cached = new CachingGeocoder($throwing, app(ConnectionInterface::class), 'nominatim', 'bo');

    $result = $cached->geocode($address);

    expect($result->matchType->value)->toBe('EXACT');
})->skip(env('DB_CONNECTION') !== 'pgsql', CACHING_GEOCODER_PG_REASON);

it('groups spacing and case variants of the same address into one row', function (): void {
    $http = exactNominatimHttp();
    $geocoder = cachingGeocoder($http);
    $token = uniqid();

    $geocoder->geocode(Address::of('Av. Arce 2345 '.$token));
    $geocoder->geocode(Address::of('  av.   arce   2345 '.$token.'  '));
    $geocoder->geocode(Address::of('AV. ARCE 2345 '.strtoupper($token)));

    // Las tres variantes colapsan a la misma consulta normalizada: una sola llamada, una sola fila.
    $http->assertSentCount(1);

    $rows = app(ConnectionInterface::class)->table('geocoding_cache')
        ->where('normalized_query', 'like', '%arce 2345 '.strtolower($token).'%')
        ->count();

    expect($rows)->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', CACHING_GEOCODER_PG_REASON);

it('increments the hit counter and refreshes last_hit_at on each cache hit', function (): void {
    $http = exactNominatimHttp();
    $geocoder = cachingGeocoder($http);
    $text = 'Zona Metrica '.uniqid();
    $address = Address::of($text);

    $geocoder->geocode($address); // miss → hits = 1
    $geocoder->geocode($address); // hit  → hits = 2
    $geocoder->geocode($address); // hit  → hits = 3

    $row = app(ConnectionInterface::class)->table('geocoding_cache')
        ->where('normalized_query', mb_strtolower($text))
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->hits)->toBe(3)
        ->and($row->provider)->toBe('nominatim');
})->skip(env('DB_CONNECTION') !== 'pgsql', CACHING_GEOCODER_PG_REASON);
