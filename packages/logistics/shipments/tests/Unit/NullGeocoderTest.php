<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Infrastructure\Geocoding\NullGeocoder;

it('returns a fixed medium confidence with no match, without calling any provider', function (): void {
    $result = (new NullGeocoder)->geocode(Address::of('Av. Siempre Viva 742'));

    expect($result->confidence->score)->toBe(50)
        ->and($result->matchType)->toBe(GeocodeMatchType::None)
        ->and($result->hasGeoPoint())->toBeFalse();
});

it('keeps a point the address already had instead of dropping it', function (): void {
    $address = Address::of('Calle 21 de Calacoto', null, GeoPoint::of(-16.53, -68.08));

    $result = (new NullGeocoder)->geocode($address);

    expect($result->geoPoint?->lat)->toBe(-16.53)
        ->and($result->geoPoint?->lng)->toBe(-68.08);
});

it('honors a configured fallback score', function (): void {
    expect((new NullGeocoder(30))->score(Address::of('x'))->score)->toBe(30);
});
