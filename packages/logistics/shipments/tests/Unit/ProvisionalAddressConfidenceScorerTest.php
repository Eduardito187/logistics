<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidenceLevel;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Infrastructure\Geocoding\ProvisionalAddressConfidenceScorer;

it('scores higher when the source already brought a coordinate', function (): void {
    $scorer = new ProvisionalAddressConfidenceScorer;

    $withPoint = $scorer->score(Address::of('Av. Arce 2345', null, GeoPoint::of(-16.5, -68.15)));
    $withoutPoint = $scorer->score(Address::of('Zona Villa Fatima, cerca de la plaza'));

    expect($withPoint->score)->toBe(70)
        ->and($withPoint->level)->toBe(AddressConfidenceLevel::High)
        ->and($withoutPoint->score)->toBe(30)
        ->and($withoutPoint->level)->toBe(AddressConfidenceLevel::Low);
});

it('does no I/O: the same scorer works offline with configured thresholds', function (): void {
    $scorer = new ProvisionalAddressConfidenceScorer(withCoordinates: 90, withoutCoordinates: 15);

    expect($scorer->score(Address::of('x', null, GeoPoint::of(0.0, 0.0)))->score)->toBe(90)
        ->and($scorer->score(Address::of('y'))->score)->toBe(15);
});
