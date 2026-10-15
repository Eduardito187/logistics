<?php

declare(strict_types=1);

use Logistics\Capacity\Domain\Service\DispatchCalendarPlanner;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Reglas de 7 días por municipio: todos los días de la semana activos, una ventana por defecto y una
 * ventana reducida el domingo (día 7), con scope de municipio.
 */
function sevenDayRuleset(): CapacityRuleset
{
    return CapacityRuleset::fromArray([
        'service_days' => [1, 2, 3, 4, 5, 6, 7],
        'order_cutoff' => '12:00',
        'horizon_days' => 7,
        'holidays' => [],
        'windows' => ['08:00-12:00', '12:00-16:00'],
        'windows_by_weekday' => [
            7 => ['09:00-13:00'],
        ],
        'totals' => ['deliveries' => 40],
        'totals_by_slot' => [],
        'scopes' => [
            ['municipality_id' => '11111111-1111-7111-8111-111111111111'],
        ],
    ]);
}

it('treats all seven weekdays as service days', function (): void {
    $ruleset = sevenDayRuleset();

    // 2026-11-02 (Lun) … 2026-11-08 (Dom): todos deben ser días de servicio.
    $cursor = new DateTimeImmutable('2026-11-02');
    for ($i = 0; $i < 7; $i++) {
        $date = SlotDate::fromDateTime($cursor);
        expect($ruleset->isServiceDay($date))->toBeTrue();
        $cursor = $cursor->add(new DateInterval('P1D'));
    }
});

it('applies the per-weekday window override (Sunday) and the default otherwise', function (): void {
    $ruleset = sevenDayRuleset();

    // 2026-11-08 es domingo (weekday 7) → ventana reducida.
    $sunday = $ruleset->windowsFor(SlotDate::fromString('2026-11-08'));
    expect($sunday)->toHaveCount(1)
        ->and($sunday[0]->toString())->toBe('09:00-13:00');

    // 2026-11-02 es lunes → ventanas por defecto.
    $monday = $ruleset->windowsFor(SlotDate::fromString('2026-11-02'));
    expect($monday)->toHaveCount(2);
});

it('plans buckets scoped by municipality across the full week', function (): void {
    $planner = new DispatchCalendarPlanner;

    $plan = $planner->plan(sevenDayRuleset(), SlotDate::fromString('2026-11-02'), 7);

    // Días 1-6: 2 ventanas; domingo: 1 ventana → 6*2 + 1 = 13 cupos (una unidad, un scope).
    expect($plan)->toHaveCount(13);

    foreach ($plan as $planned) {
        expect($planned->scope->municipalityId)->toBe('11111111-1111-7111-8111-111111111111')
            ->and($planned->scope->nodeId)->toBeNull()
            ->and($planned->scope->zoneId)->toBeNull();
    }
});
