<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Http\Client\Request;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodeScoring;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodingException;
use Logistics\Shipments\Infrastructure\Geocoding\NominatimGeocoder;

function nominatimGeocoder(HttpClient $http, ?Repository $cache = null): NominatimGeocoder
{
    return new NominatimGeocoder(
        $http,
        $cache ?? new Repository(new ArrayStore),
        GeocodeScoring::fromConfig([]),
        'https://nominatim.test/search',
        'DismacLogisticsTest/1.0 (test@dismac.test)',
        'bo',
        'es',
        5,
        3600,
        0, // sin throttle en tests
    );
}

it('maps a building result to an exact match with its coordinate', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5000',
        'lon' => '-68.1500',
        'addresstype' => 'building',
        'class' => 'building',
        'type' => 'yes',
    ]])]);

    $result = nominatimGeocoder($http)->geocode(Address::of('Av. Arce 2345, La Paz'));

    expect($result->matchType)->toBe(GeocodeMatchType::Exact)
        ->and($result->confidence->score)->toBe(95)
        ->and($result->geoPoint?->lat)->toBe(-16.5)
        ->and($result->geoPoint?->lng)->toBe(-68.15);
});

it('maps a road result to an interpolated match', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5',
        'lon' => '-68.15',
        'addresstype' => 'road',
        'class' => 'highway',
        'type' => 'residential',
    ]])]);

    $result = nominatimGeocoder($http)->geocode(Address::of('Av. 6 de Agosto'));

    expect($result->matchType)->toBe(GeocodeMatchType::Interpolated)
        ->and($result->confidence->score)->toBe(80);
});

it('maps a locality result to an approximate match', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5',
        'lon' => '-68.15',
        'addresstype' => 'city',
        'class' => 'place',
        'type' => 'city',
    ]])]);

    $result = nominatimGeocoder($http)->geocode(Address::of('La Paz'));

    expect($result->matchType)->toBe(GeocodeMatchType::Approximate)
        ->and($result->confidence->score)->toBe(45);
});

it('returns a not-found result for an empty response', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([])]);

    $result = nominatimGeocoder($http)->geocode(Address::of('zzz inexistente'));

    expect($result->matchType)->toBe(GeocodeMatchType::None)
        ->and($result->hasGeoPoint())->toBeFalse();
});

it('sends the required identifiable User-Agent header', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5', 'lon' => '-68.15', 'addresstype' => 'building',
    ]])]);

    nominatimGeocoder($http)->geocode(Address::of('Av. Arce'));

    $http->assertSent(fn (Request $request): bool => $request->hasHeader('User-Agent', 'DismacLogisticsTest/1.0 (test@dismac.test)'));
});

it('raises a geocoding exception on an HTTP error', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response('', 503)]);

    expect(fn () => nominatimGeocoder($http)->geocode(Address::of('x')))
        ->toThrow(GeocodingException::class, 'HTTP 503');
});

it('serves repeated lookups from cache without a second request', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([[
        'lat' => '-16.5', 'lon' => '-68.15', 'addresstype' => 'building',
    ]])]);

    $geocoder = nominatimGeocoder($http);
    $address = Address::of('Av. Arce 2345');

    $geocoder->geocode($address);
    $geocoder->geocode($address);

    $http->assertSentCount(1);
});
