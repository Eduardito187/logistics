<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodeScoring;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodingException;
use Logistics\Shipments\Infrastructure\Geocoding\GoogleGeocoder;

function googleGeocoder(HttpClient $http, int $dailyQuota = 0, ?Repository $cache = null): GoogleGeocoder
{
    return new GoogleGeocoder(
        $http,
        $cache ?? new Repository(new ArrayStore),
        GeocodeScoring::fromConfig([]),
        'test-key',
        'https://maps.googleapis.test/geocode/json',
        'bo',
        'es',
        5,
        3600,
        $dailyQuota,
    );
}

it('scores a rooftop result as an exact match with its coordinate', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([
        'status' => 'OK',
        'results' => [[
            'geometry' => [
                'location_type' => 'ROOFTOP',
                'location' => ['lat' => -16.5, 'lng' => -68.15],
            ],
        ]],
    ])]);

    $result = googleGeocoder($http)->geocode(Address::of('Av. Arce 2345, La Paz'));

    expect($result->matchType)->toBe(GeocodeMatchType::Exact)
        ->and($result->confidence->score)->toBe(95)
        ->and($result->geoPoint?->lat)->toBe(-16.5)
        ->and($result->geoPoint?->lng)->toBe(-68.15);
});

it('applies the partial-match penalty to the score', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([
        'status' => 'OK',
        'results' => [[
            'partial_match' => true,
            'geometry' => ['location_type' => 'ROOFTOP', 'location' => ['lat' => -16.5, 'lng' => -68.15]],
        ]],
    ])]);

    $result = googleGeocoder($http)->geocode(Address::of('Av. Arce, La Paz'));

    expect($result->matchType)->toBe(GeocodeMatchType::Exact)
        ->and($result->confidence->score)->toBe(70); // 95 - 25
});

it('maps an approximate result to a low-ish approximate match', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response([
        'status' => 'OK',
        'results' => [['geometry' => ['location_type' => 'APPROXIMATE', 'location' => ['lat' => -16.5, 'lng' => -68.15]]]],
    ])]);

    $result = googleGeocoder($http)->geocode(Address::of('El Alto'));

    expect($result->matchType)->toBe(GeocodeMatchType::Approximate)
        ->and($result->confidence->score)->toBe(45);
});

it('returns a not-found result for ZERO_RESULTS', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    $result = googleGeocoder($http)->geocode(Address::of('direccion inexistente zzz'));

    expect($result->matchType)->toBe(GeocodeMatchType::None)
        ->and($result->hasGeoPoint())->toBeFalse()
        ->and($result->confidence->isLow())->toBeTrue();
});

it('raises a geocoding exception when the daily quota is exceeded', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response(['status' => 'OK', 'results' => [
        ['geometry' => ['location_type' => 'ROOFTOP', 'location' => ['lat' => -16.5, 'lng' => -68.15]]],
    ]])]);

    $geocoder = googleGeocoder($http, dailyQuota: 1);

    $geocoder->geocode(Address::of('primera direccion'));

    expect(fn () => $geocoder->geocode(Address::of('segunda direccion')))
        ->toThrow(GeocodingException::class, 'cuota diaria');
});

it('raises a geocoding exception when Google returns a transport error', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response('', 500)]);

    expect(fn () => googleGeocoder($http)->geocode(Address::of('x')))
        ->toThrow(GeocodingException::class, 'HTTP 500');
});

it('serves repeated lookups from cache without a second request', function (): void {
    $http = new HttpClient;
    $http->fake(['*' => HttpClient::response(['status' => 'OK', 'results' => [
        ['geometry' => ['location_type' => 'ROOFTOP', 'location' => ['lat' => -16.5, 'lng' => -68.15]]],
    ]])]);

    $geocoder = googleGeocoder($http);
    $address = Address::of('Av. Arce 2345, La Paz');

    $geocoder->geocode($address);
    $geocoder->geocode($address);

    $http->assertSentCount(1);
});
