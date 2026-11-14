<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Logistics\Labels\Domain\ValueObject\LabelSizeMatch;
use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;
use Logistics\Labels\Infrastructure\Settings\SettingsLabelSizePolicyProvider;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/** Bus de consultas que siempre responde con un valor fijo (simula settings con/sin versión publicada). */
function sizeQueryBusReturning(mixed $value): QueryBus
{
    return new class($value) implements QueryBus
    {
        public function __construct(private mixed $value) {}

        public function ask(Query $query): mixed
        {
            return $this->value;
        }
    };
}

/**
 * Config del package con el bloque `size` por defecto.
 *
 * @param array<string, mixed> $size
 */
function labelsSizeConfig(array $size): Repository
{
    return new Repository(['logistics-labels' => ['size' => $size]]);
}

it('uses the versioned settings value when a published version exists', function (): void {
    $provider = new SettingsLabelSizePolicyProvider(
        sizeQueryBusReturning([
            'default_profile' => 'lite',
            'small_profile' => 'micro',
            'small_max_longest_side_cm' => 10,
            'small_max_weight_kg' => 1,
            'rules' => [
                ['match' => 'sku_prefix', 'value' => 'SAMS', 'profile' => 'lite_40x30'],
                ['match' => 'sku_exact', 'value' => '', 'profile' => 'lite'], // se descarta (sin valor)
            ],
        ]),
        labelsSizeConfig(['default_profile' => 'standard']),
    );

    $policy = $provider->current(new DateTimeImmutable('2026-11-12T12:00:00'));

    expect($policy->defaultProfile)->toBe(LabelSizeProfile::Lite)
        ->and($policy->smallProfile)->toBe(LabelSizeProfile::Micro)
        ->and($policy->smallMaxLongestSideCm)->toBe(10.0)
        ->and($policy->smallMaxWeightKg)->toBe(1.0)
        ->and($policy->rules)->toHaveCount(1)
        ->and($policy->rules[0]->match)->toBe(LabelSizeMatch::SkuPrefix)
        ->and($policy->rules[0]->profile)->toBe(LabelSizeProfile::Lite40x30);
});

it('falls back to the package config when settings has no published version', function (): void {
    $provider = new SettingsLabelSizePolicyProvider(
        sizeQueryBusReturning(null),
        labelsSizeConfig([
            'default_profile' => 'standard',
            'small_profile' => 'lite',
            'small_max_longest_side_cm' => 25.0,
            'small_max_weight_kg' => 2.0,
            'rules' => [],
        ]),
    );

    $policy = $provider->current(new DateTimeImmutable('2026-11-12T12:00:00'));

    expect($policy->defaultProfile)->toBe(LabelSizeProfile::Standard)
        ->and($policy->smallProfile)->toBe(LabelSizeProfile::Lite)
        ->and($policy->smallMaxLongestSideCm)->toBe(25.0)
        ->and($policy->rules)->toBe([]);
});

it('uses the policy defaults when neither settings nor config give usable values', function (): void {
    $provider = new SettingsLabelSizePolicyProvider(
        sizeQueryBusReturning(null),
        new Repository(['logistics-labels' => []]),
    );

    $policy = $provider->current(new DateTimeImmutable('2026-11-12T12:00:00'));

    expect($policy->defaultProfile)->toBe(LabelSizeProfile::Standard)
        ->and($policy->smallProfile)->toBe(LabelSizeProfile::Lite)
        ->and($policy->smallMaxLongestSideCm)->toBe(25.0)
        ->and($policy->smallMaxWeightKg)->toBe(2.0);
});
