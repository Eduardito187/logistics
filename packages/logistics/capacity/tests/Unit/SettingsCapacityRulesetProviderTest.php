<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Logistics\Capacity\Infrastructure\Settings\SettingsCapacityRulesetProvider;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Bus de consultas que siempre responde con un valor fijo (simula settings con/sin versión publicada).
 */
function queryBusReturning(mixed $value): QueryBus
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
 * Config del package con los valores por defecto del calendario de despacho.
 */
function capacityConfig(): Repository
{
    return new Repository([
        'logistics-capacity' => [
            'default_totals' => ['deliveries' => 120, 'weight' => 8000, 'volume' => 40],
            'dispatch_calendar' => [
                'business_days' => [1, 2, 3, 4, 5, 6],
                'order_cutoff' => '12:00',
                'horizon_days' => 14,
                'windows' => ['08:00-12:00', '12:00-16:00', '16:00-20:00'],
                'default_slot_deliveries' => 40,
            ],
        ],
    ]);
}

it('builds the ruleset from the versioned settings value when one exists', function (): void {
    $value = [
        'service_days' => [1, 2, 3],
        'order_cutoff' => '10:00',
        'horizon_days' => 5,
        'holidays' => ['2026-12-25'],
        'windows' => ['09:00-13:00'],
        'windows_by_weekday' => [],
        'totals' => ['deliveries' => 25],
        'totals_by_slot' => [],
        'scopes' => [['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null]],
    ];

    $provider = new SettingsCapacityRulesetProvider(queryBusReturning($value), capacityConfig());
    $ruleset = $provider->current(new DateTimeImmutable('2026-10-13T12:00:00'));

    expect($ruleset->toArray())->toBe([
        'service_days' => [1, 2, 3],
        'order_cutoff' => '10:00',
        'horizon_days' => 5,
        'holidays' => ['2026-12-25'],
        'windows' => ['09:00-13:00'],
        'windows_by_weekday' => [],
        'totals' => ['deliveries' => 25.0],
        'totals_by_slot' => [],
        'scopes' => [['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null]],
    ]);
});

it('falls back to package config when settings has no published version', function (): void {
    $provider = new SettingsCapacityRulesetProvider(queryBusReturning(null), capacityConfig());
    $ruleset = $provider->current(new DateTimeImmutable('2026-10-13T12:00:00'));

    $array = $ruleset->toArray();

    expect($array['service_days'])->toBe([1, 2, 3, 4, 5, 6])
        ->and($array['order_cutoff'])->toBe('12:00')
        ->and($array['horizon_days'])->toBe(14)
        ->and($array['windows'])->toBe(['08:00-12:00', '12:00-16:00', '16:00-20:00'])
        ->and($array['totals'])->toBe(['deliveries' => 40.0, 'weight' => 8000.0, 'volume' => 40.0])
        ->and($array['holidays'])->toBe([])
        ->and($array['scopes'])->toBe([]);
});
