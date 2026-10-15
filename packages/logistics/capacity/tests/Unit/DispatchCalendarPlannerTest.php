<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Service\DispatchCalendarPlanner;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\PlannedBucket;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Reglas base para el planner: todos los días son de servicio (para aislar la lógica del weekday), una
 * ventana, una unidad con cupo y un scope de nodo.
 *
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function plannerRules(array $overrides = []): array
{
    return $overrides + [
        'service_days' => [1, 2, 3, 4, 5, 6, 7],
        'order_cutoff' => '12:00',
        'horizon_days' => 3,
        'holidays' => [],
        'windows' => ['08:00-12:00'],
        'windows_by_weekday' => [],
        'totals' => ['deliveries' => 40],
        'totals_by_slot' => [],
        'scopes' => [['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null]],
    ];
}

/**
 * @param  list<PlannedBucket> $plan
 * @return list<string>
 */
function planDates(array $plan): array
{
    return array_values(array_unique(array_map(static fn (PlannedBucket $p): string => $p->date->toString(), $plan)));
}

it('plans one bucket per service day, window, unit and scope across the horizon', function (): void {
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules()),
        SlotDate::fromString('2026-03-02'),
    );

    expect($plan)->toHaveCount(3)
        ->and(planDates($plan))->toBe(['2026-03-02', '2026-03-03', '2026-03-04']);

    $first = $plan[0];
    expect($first->scope->nodeId)->toBe('11111111-1111-7111-8111-111111111111')
        ->and($first->slot->toString())->toBe('08:00-12:00')
        ->and($first->unit->value)->toBe('deliveries')
        ->and($first->total->value())->toBe(40.0);
});

it('skips holidays and still fills the horizon with service days', function (): void {
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules(['holidays' => ['2026-03-03']])),
        SlotDate::fromString('2026-03-02'),
    );

    expect(planDates($plan))->toBe(['2026-03-02', '2026-03-04', '2026-03-05']);
});

it('skips weekdays that are not service days', function (): void {
    // Solo lunes a viernes. 2026-03-07 es sábado y 2026-03-08 domingo: se saltan.
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules(['service_days' => [1, 2, 3, 4, 5], 'horizon_days' => 6])),
        SlotDate::fromString('2026-03-02'),
    );

    expect(planDates($plan))->toBe([
        '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-09',
    ]);
});

it('honors the horizon override', function (): void {
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules()),
        SlotDate::fromString('2026-03-02'),
        horizonOverride: 1,
    );

    expect(planDates($plan))->toBe(['2026-03-02']);
});

it('plans nothing when there are no scopes', function (): void {
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules(['scopes' => []])),
        SlotDate::fromString('2026-03-02'),
    );

    expect($plan)->toBe([]);
});

it('multiplies buckets by windows, units and scopes', function (): void {
    $plan = (new DispatchCalendarPlanner)->plan(
        CapacityRuleset::fromArray(plannerRules([
            'horizon_days' => 1,
            'windows' => ['08:00-12:00', '12:00-16:00'],
            'totals' => ['deliveries' => 40, 'weight' => 8000],
            'scopes' => [
                ['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null],
                ['node_id' => null, 'zone_id' => '22222222-2222-7222-8222-222222222222'],
            ],
        ])),
        SlotDate::fromString('2026-03-02'),
    );

    // 1 día × 2 ventanas × 2 unidades × 2 scopes = 8.
    expect($plan)->toHaveCount(8);
});
