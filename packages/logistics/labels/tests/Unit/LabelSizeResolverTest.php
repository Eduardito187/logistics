<?php

declare(strict_types=1);

use Logistics\Labels\Domain\Service\LabelSizeResolver;
use Logistics\Labels\Domain\ValueObject\LabelSizeMatch;
use Logistics\Labels\Domain\ValueObject\LabelSizePolicy;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSizeRule;
use Logistics\Labels\Domain\ValueObject\ProductMeasures;

function resolver(): LabelSizeResolver
{
    return new LabelSizeResolver;
}

/** Medidas de un celular (chico): lado más largo 16 cm, 0,25 kg. */
function phoneMeasures(): ProductMeasures
{
    return new ProductMeasures(weightKg: 0.25, lengthCm: 16.0, widthCm: 8.0, heightCm: 1.0, volumeM3: null);
}

/** Medidas de un ropero (grande): lado más largo 200 cm. */
function wardrobeMeasures(): ProductMeasures
{
    return new ProductMeasures(weightKg: 60.0, lengthCm: 200.0, widthCm: 60.0, heightCm: 60.0, volumeM3: null);
}

it('degrades to the default profile when there are no measures and no rule', function (): void {
    expect(resolver()->resolve('SKU-X', null, LabelSizePolicy::default()))
        ->toBe(LabelSizeProfile::Standard);
});

it('degrades to the default profile for an empty measures value object', function (): void {
    $empty = new ProductMeasures(null, null, null, null, null);

    expect(resolver()->resolve('SKU-X', $empty, LabelSizePolicy::default()))
        ->toBe(LabelSizeProfile::Standard);
});

it('picks the small profile when the product measures qualify as small', function (): void {
    expect(resolver()->resolve('SKU-PHONE', phoneMeasures(), LabelSizePolicy::default()))
        ->toBe(LabelSizeProfile::Lite);
});

it('keeps the default profile when the product is too large', function (): void {
    expect(resolver()->resolve('SKU-WARDROBE', wardrobeMeasures(), LabelSizePolicy::default()))
        ->toBe(LabelSizeProfile::Standard);
});

it('keeps the default profile when a small product is too heavy', function (): void {
    $heavySmall = new ProductMeasures(weightKg: 10.0, lengthCm: 16.0, widthCm: 8.0, heightCm: 1.0, volumeM3: null);

    expect(resolver()->resolve('SKU-HEAVY', $heavySmall, LabelSizePolicy::default()))
        ->toBe(LabelSizeProfile::Standard);
});

it('lets an SKU rule win over the measure-based decision', function (): void {
    $policy = new LabelSizePolicy(
        defaultProfile: LabelSizeProfile::Standard,
        smallProfile: LabelSizeProfile::Lite,
        rules: [new LabelSizeRule(LabelSizeMatch::SkuPrefix, 'SAMS', LabelSizeProfile::Lite40x30)],
        smallMaxLongestSideCm: 25.0,
        smallMaxWeightKg: 2.0,
    );

    // El producto es chico por medidas (iría a Lite), pero la regla por prefijo manda: Lite40x30.
    expect(resolver()->resolve('SAMS-A15', phoneMeasures(), $policy))
        ->toBe(LabelSizeProfile::Lite40x30);
});

it('honours an explicit print override above everything else', function (): void {
    $policy = new LabelSizePolicy(
        defaultProfile: LabelSizeProfile::Standard,
        smallProfile: LabelSizeProfile::Lite,
        rules: [new LabelSizeRule(LabelSizeMatch::SkuExact, 'SAMS-A15', LabelSizeProfile::Lite)],
        smallMaxLongestSideCm: 25.0,
        smallMaxWeightKg: 2.0,
    );

    expect(resolver()->resolve('SAMS-A15', phoneMeasures(), $policy, LabelSizeProfile::Micro))
        ->toBe(LabelSizeProfile::Micro);
});

it('honours a line override above rules and measures but below the print override', function (): void {
    $policy = LabelSizePolicy::default();

    expect(resolver()->resolve('SKU-X', phoneMeasures(), $policy, null, LabelSizeProfile::Lite40x30))
        ->toBe(LabelSizeProfile::Lite40x30);
});

it('disables the measure rule when the longest-side threshold is null', function (): void {
    $policy = new LabelSizePolicy(
        defaultProfile: LabelSizeProfile::Standard,
        smallProfile: LabelSizeProfile::Lite,
        rules: [],
        smallMaxLongestSideCm: null,
        smallMaxWeightKg: 2.0,
    );

    expect(resolver()->resolve('SKU-PHONE', phoneMeasures(), $policy))
        ->toBe(LabelSizeProfile::Standard);
});
