<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\LabelSizeMatch;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Domain\ValueObject\LabelSizeRule;

it('matches an exact SKU case-insensitively and ignores surrounding spaces', function (): void {
    $rule = new LabelSizeRule(LabelSizeMatch::SkuExact, 'SAMS-A15', LabelSizeProfile::Lite);

    expect($rule->matches('sams-a15'))->toBeTrue()
        ->and($rule->matches(' SAMS-A15 '))->toBeTrue()
        ->and($rule->matches('SAMS-A16'))->toBeFalse();
});

it('matches an SKU prefix', function (): void {
    $rule = new LabelSizeRule(LabelSizeMatch::SkuPrefix, 'SAMS', LabelSizeProfile::Lite);

    expect($rule->matches('SAMS-A15'))->toBeTrue()
        ->and($rule->matches('sams-zflip'))->toBeTrue()
        ->and($rule->matches('LG-OLED'))->toBeFalse();
});

it('builds from primitives tolerantly and drops rules with no value', function (): void {
    $rule = LabelSizeRule::fromArray(['match' => 'sku_prefix', 'value' => 'SAMS', 'profile' => 'lite']);

    expect($rule)->not->toBeNull()
        ->and($rule->match)->toBe(LabelSizeMatch::SkuPrefix)
        ->and($rule->value)->toBe('SAMS')
        ->and($rule->profile)->toBe(LabelSizeProfile::Lite);

    // Valor vacío => regla sin sentido => null.
    expect(LabelSizeRule::fromArray(['match' => 'sku_exact', 'value' => '  ', 'profile' => 'lite']))->toBeNull();

    // match/profile desconocidos caen a sus defaults tolerantes.
    $fallback = LabelSizeRule::fromArray(['match' => 'xxx', 'value' => 'ABC', 'profile' => 'yyy']);
    expect($fallback->match)->toBe(LabelSizeMatch::SkuExact)
        ->and($fallback->profile)->toBe(LabelSizeProfile::Standard);
});

it('round-trips to and from an array', function (): void {
    $rule = new LabelSizeRule(LabelSizeMatch::SkuPrefix, 'LG', LabelSizeProfile::Micro);

    expect($rule->toArray())->toBe(['match' => 'sku_prefix', 'value' => 'LG', 'profile' => 'micro']);
});
