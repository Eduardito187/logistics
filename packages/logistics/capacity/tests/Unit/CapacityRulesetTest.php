<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Arreglo de reglas representativo y completo para las pruebas.
 *
 * @return array<string, mixed>
 */
function rulesetArray(): array
{
    return [
        'service_days' => [1, 2, 3, 4, 5],
        'order_cutoff' => '12:00',
        'horizon_days' => 7,
        'holidays' => ['2026-10-14'],
        'windows' => ['08:00-12:00', '12:00-16:00'],
        'windows_by_weekday' => [6 => ['09:00-13:00']],
        'totals' => ['deliveries' => 40, 'weight' => 8000],
        'totals_by_slot' => ['deliveries' => ['08:00-12:00' => 60]],
        'scopes' => [
            ['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null],
        ],
    ];
}

it('round-trips its primitive form through fromArray/toArray', function (): void {
    $ruleset = CapacityRuleset::fromArray(rulesetArray());

    $array = $ruleset->toArray();

    expect($array['service_days'])->toBe([1, 2, 3, 4, 5])
        ->and($array['order_cutoff'])->toBe('12:00')
        ->and($array['horizon_days'])->toBe(7)
        ->and($array['holidays'])->toBe(['2026-10-14'])
        ->and($array['windows'])->toBe(['08:00-12:00', '12:00-16:00'])
        ->and($array['windows_by_weekday'])->toBe([6 => ['09:00-13:00']])
        ->and($array['totals'])->toBe(['deliveries' => 40.0, 'weight' => 8000.0])
        ->and($array['totals_by_slot'])->toBe(['deliveries' => ['08:00-12:00' => 60.0]])
        ->and($array['scopes'])->toBe([['node_id' => '11111111-1111-7111-8111-111111111111', 'zone_id' => null, 'municipality_id' => null]]);

    // Reconstruir desde toArray da el mismo resultado (idempotencia).
    expect(CapacityRuleset::fromArray($array)->toArray())->toBe($array);
});

it('sorts and de-duplicates service days', function (): void {
    $ruleset = CapacityRuleset::fromArray(['service_days' => [5, 1, 1, 3]] + rulesetArray());

    expect($ruleset->serviceDays())->toBe([1, 3, 5]);
});

it('treats a day as a service day only on an enabled weekday that is not a holiday', function (): void {
    $ruleset = CapacityRuleset::fromArray(rulesetArray());

    // 2026-10-13 es martes (weekday 2): día de servicio.
    $tuesday = SlotDate::fromString('2026-10-13');
    // 2026-10-14 es miércoles pero está en feriados.
    $holiday = SlotDate::fromString('2026-10-14');
    // 2026-10-17 es sábado (weekday 6): no está en service_days [1..5].
    $saturday = SlotDate::fromString('2026-10-17');

    expect((int) $tuesday->toDateTime()->format('N'))->toBe(2)
        ->and($ruleset->isServiceDay($tuesday))->toBeTrue()
        ->and($ruleset->isHoliday($holiday))->toBeTrue()
        ->and($ruleset->isServiceDay($holiday))->toBeFalse()
        ->and($ruleset->isServiceDay($saturday))->toBeFalse();
});

it('returns per-weekday window overrides, falling back to the default windows', function (): void {
    $ruleset = CapacityRuleset::fromArray(rulesetArray());

    // 2026-10-17 es sábado (weekday 6): tiene override.
    $saturday = SlotDate::fromString('2026-10-17');
    // 2026-10-13 es martes (weekday 2): sin override → ventanas por defecto.
    $tuesday = SlotDate::fromString('2026-10-13');

    $saturdayWindows = array_map(static fn (TimeSlot $w): string => $w->toString(), $ruleset->windowsFor($saturday));
    $tuesdayWindows = array_map(static fn (TimeSlot $w): string => $w->toString(), $ruleset->windowsFor($tuesday));

    expect($saturdayWindows)->toBe(['09:00-13:00'])
        ->and($tuesdayWindows)->toBe(['08:00-12:00', '12:00-16:00']);
});

it('prefers a per-slot total over the unit default and returns null for units without a rule', function (): void {
    $ruleset = CapacityRuleset::fromArray(rulesetArray());

    $perSlot = $ruleset->totalFor(CapacityUnit::Deliveries, TimeSlot::fromString('08:00-12:00'));
    $default = $ruleset->totalFor(CapacityUnit::Deliveries, TimeSlot::fromString('12:00-16:00'));
    $weight = $ruleset->totalFor(CapacityUnit::Weight, TimeSlot::fromString('08:00-12:00'));
    $volume = $ruleset->totalFor(CapacityUnit::Volume, TimeSlot::fromString('08:00-12:00'));

    expect($perSlot?->value())->toBe(60.0)
        ->and($default?->value())->toBe(40.0)
        ->and($weight?->value())->toBe(8000.0)
        ->and($volume)->toBeNull();
});

it('lists only the units that have a capacity rule, in enum order', function (): void {
    $ruleset = CapacityRuleset::fromArray(rulesetArray());

    $units = array_map(static fn (CapacityUnit $u): string => $u->value, $ruleset->units());

    expect($units)->toBe(['deliveries', 'weight']);
});

it('rejects an invalid order cutoff', function (): void {
    CapacityRuleset::fromArray(['order_cutoff' => '25:00'] + rulesetArray());
})->throws(CapacityException::class);

it('rejects a weekday out of range', function (): void {
    CapacityRuleset::fromArray(['service_days' => [0, 8]] + rulesetArray());
})->throws(CapacityException::class);

it('rejects a horizon outside the allowed range', function (): void {
    CapacityRuleset::fromArray(['horizon_days' => 0] + rulesetArray());
})->throws(CapacityException::class);

it('rejects a scope that is neither a node nor a zone', function (): void {
    CapacityRuleset::fromArray(['scopes' => [['node_id' => null, 'zone_id' => null]]] + rulesetArray());
})->throws(CapacityException::class);
