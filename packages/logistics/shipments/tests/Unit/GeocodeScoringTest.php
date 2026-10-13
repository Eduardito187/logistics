<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;
use Logistics\Shipments\Infrastructure\Geocoding\GeocodeScoring;

it('uses sensible default scores per match type', function (): void {
    $scoring = GeocodeScoring::fromConfig([]);

    expect($scoring->scoreFor(GeocodeMatchType::Exact))->toBe(95)
        ->and($scoring->scoreFor(GeocodeMatchType::Interpolated))->toBe(80)
        ->and($scoring->scoreFor(GeocodeMatchType::Approximate))->toBe(45)
        ->and($scoring->scoreFor(GeocodeMatchType::None))->toBe(10)
        ->and($scoring->noResultScore())->toBe(10);
});

it('subtracts the partial match penalty and clamps at zero', function (): void {
    $scoring = GeocodeScoring::fromConfig([
        'match_scores' => ['exact' => 95, 'approximate' => 20],
        'partial_match_penalty' => 25,
    ]);

    expect($scoring->scoreFor(GeocodeMatchType::Exact, partialMatch: true))->toBe(70)
        ->and($scoring->scoreFor(GeocodeMatchType::Approximate, partialMatch: true))->toBe(0);
});

it('honors configured thresholds for exact vs approximate matches', function (): void {
    $scoring = GeocodeScoring::fromConfig([
        'match_scores' => [
            'exact' => 100,
            'interpolated' => 65,
            'approximate' => 30,
            'none' => 5,
        ],
        'no_result_score' => 7,
    ]);

    expect($scoring->scoreFor(GeocodeMatchType::Exact))->toBe(100)
        ->and($scoring->scoreFor(GeocodeMatchType::Interpolated))->toBe(65)
        ->and($scoring->scoreFor(GeocodeMatchType::Approximate))->toBe(30)
        ->and($scoring->noResultScore())->toBe(7);
});

it('clamps configured scores into 0..100', function (): void {
    $scoring = GeocodeScoring::fromConfig([
        'match_scores' => ['exact' => 250, 'none' => -40],
    ]);

    expect($scoring->scoreFor(GeocodeMatchType::Exact))->toBe(100)
        ->and($scoring->scoreFor(GeocodeMatchType::None))->toBe(0);
});

it('accepts numeric strings from config (env always returns strings)', function (): void {
    $scoring = GeocodeScoring::fromConfig([
        'match_scores' => ['exact' => '90'],
        'partial_match_penalty' => '10',
    ]);

    expect($scoring->scoreFor(GeocodeMatchType::Exact))->toBe(90)
        ->and($scoring->scoreFor(GeocodeMatchType::Exact, partialMatch: true))->toBe(80);
});
