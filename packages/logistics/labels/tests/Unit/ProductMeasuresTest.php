<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\ProductMeasures;

it('returns the longest side ignoring null and non-positive dimensions', function (): void {
    $measures = new ProductMeasures(weightKg: 1.0, lengthCm: 16.0, widthCm: null, heightCm: 1.5, volumeM3: null);

    expect($measures->longestSideCm())->toBe(16.0);
});

it('returns null for the longest side when no dimension is known', function (): void {
    $measures = new ProductMeasures(weightKg: 2.0, lengthCm: null, widthCm: null, heightCm: null, volumeM3: null);

    expect($measures->longestSideCm())->toBeNull();
});

it('is empty only when nothing is known', function (): void {
    expect((new ProductMeasures(null, null, null, null, null))->isEmpty())->toBeTrue()
        ->and((new ProductMeasures(null, 10.0, null, null, null))->isEmpty())->toBeFalse();
});
