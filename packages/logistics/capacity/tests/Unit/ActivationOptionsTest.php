<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Query\GetActivationOptions\GetActivationOptionsHandler;
use Logistics\Capacity\Application\Query\GetActivationOptions\GetActivationOptionsQuery;
use Logistics\Capacity\Domain\Contract\CapacityRulesetProvider;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Catálogo de activación de cupos: franjas ofrecidas y ventanas por defecto que salen de las reglas, y el
 * query handler que las expone en su forma primitiva.
 *
 * @param  array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function activationOptionsRules(array $overrides = []): array
{
    return array_merge([
        'service_days' => [1, 2, 3, 4, 5],
        'order_cutoff' => '12:00',
        'horizon_days' => 7,
        'holidays' => [],
        'windows' => ['16:00-20:00', '08:00-12:00'],
        'windows_by_weekday' => [6 => ['09:00-13:00', '08:00-12:00']],
        'totals' => ['deliveries' => 40, 'weight' => 8000],
        'totals_by_slot' => ['deliveries' => ['08:00-12:00' => 60]],
        'scopes' => [],
    ], $overrides);
}

function activationOptionsHandler(CapacityRuleset $ruleset): GetActivationOptionsHandler
{
    $provider = new class($ruleset) implements CapacityRulesetProvider
    {
        public function __construct(private CapacityRuleset $ruleset) {}

        public function current(DateTimeImmutable $at): CapacityRuleset
        {
            return $this->ruleset;
        }

        public function currentForMunicipality(?string $municipalityId, DateTimeImmutable $at): CapacityRuleset
        {
            return $this->ruleset;
        }
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-01-01T00:00:00');
        }
    };

    return new GetActivationOptionsHandler($provider, $clock);
}

it('lists the offered windows without duplicates, sorted, including weekday overrides', function (): void {
    $ruleset = CapacityRuleset::fromArray(activationOptionsRules());

    expect(array_map(static fn (TimeSlot $slot): string => $slot->toString(), $ruleset->offeredWindows()))
        ->toBe(['08:00-12:00', '09:00-13:00', '16:00-20:00']);
});

it('builds the default activation windows from default slots, units and totals', function (): void {
    $ruleset = CapacityRuleset::fromArray(activationOptionsRules());

    expect(array_map(static fn (ActivationWindow $w): array => $w->toArray(), $ruleset->defaultActivationWindows()))
        ->toBe([
            ['time_slot' => '16:00-20:00', 'unit' => 'deliveries', 'quantity' => 40.0],
            ['time_slot' => '16:00-20:00', 'unit' => 'weight', 'quantity' => 8000.0],
            // El afinamiento por franja gana sobre el total de la unidad.
            ['time_slot' => '08:00-12:00', 'unit' => 'deliveries', 'quantity' => 60.0],
            ['time_slot' => '08:00-12:00', 'unit' => 'weight', 'quantity' => 8000.0],
        ]);
});

it('has no default activation windows without default slots or without capacity', function (): void {
    $withoutSlots = CapacityRuleset::fromArray(activationOptionsRules(['windows' => []]));
    $withoutCapacity = CapacityRuleset::fromArray(activationOptionsRules([
        'totals' => ['deliveries' => 0],
        'totals_by_slot' => [],
    ]));

    expect($withoutSlots->defaultActivationWindows())->toBe([])
        ->and($withoutCapacity->defaultActivationWindows())->toBe([]);
});

it('returns the activation options in their primitive form', function (): void {
    $view = activationOptionsHandler(CapacityRuleset::fromArray(activationOptionsRules()))
        ->handle(new GetActivationOptionsQuery);

    expect($view->hasDefaultWindows())->toBeTrue()
        ->and($view->toArray()['time_slots'])->toBe(['08:00-12:00', '09:00-13:00', '16:00-20:00'])
        ->and(array_column($view->toArray()['units'], 'value'))->toBe(['deliveries', 'weight', 'volume', 'appointments'])
        ->and($view->toArray()['weekdays'])->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($view->toArray()['service_days'])->toBe([1, 2, 3, 4, 5])
        ->and($view->toArray()['default_windows'])->toHaveCount(4);
});

it('rejects an invalid reference date', function (): void {
    activationOptionsHandler(CapacityRuleset::fromArray(activationOptionsRules()))
        ->handle(new GetActivationOptionsQuery('no-es-fecha'));
})->throws(CapacityException::class);
