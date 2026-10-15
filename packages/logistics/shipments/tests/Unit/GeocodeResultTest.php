<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Domain\ValueObject\GeocodeResult;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;

it('carries confidence, match type and the located point', function (): void {
    $result = GeocodeResult::of(
        AddressConfidence::of(95),
        GeocodeMatchType::Exact,
        GeoPoint::of(-16.5, -68.15),
    );

    expect($result->confidence->score)->toBe(95)
        ->and($result->matchType)->toBe(GeocodeMatchType::Exact)
        ->and($result->hasGeoPoint())->toBeTrue()
        ->and($result->geoPoint?->lat)->toBe(-16.5);
});

it('builds a not-found result with no point and a None match', function (): void {
    $result = GeocodeResult::notFound(AddressConfidence::of(10));

    expect($result->matchType)->toBe(GeocodeMatchType::None)
        ->and($result->hasGeoPoint())->toBeFalse()
        ->and($result->geoPoint)->toBeNull()
        ->and($result->confidence->isLow())->toBeTrue();
});
