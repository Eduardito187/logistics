<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\TriggerType;

// Enums de disparador y alcance: fromString tolerante (trim + mayúsculas) y rechazo de lo inválido.

it('TriggerType normaliza y resuelve desde cadena', function (): void {
    expect(TriggerType::fromString(' damaged_product '))->toBe(TriggerType::DamagedProduct)
        ->and(TriggerType::fromString('late_delivery'))->toBe(TriggerType::LateDelivery);
});

it('TriggerType rechaza un disparador inválido', function (): void {
    expect(fn () => TriggerType::fromString('NOT_A_TRIGGER'))->toThrow(CompensationException::class);
});

it('CompensationScope normaliza y resuelve desde cadena', function (): void {
    expect(CompensationScope::fromString(' line '))->toBe(CompensationScope::Line)
        ->and(CompensationScope::fromString('order'))->toBe(CompensationScope::Order);
});

it('CompensationScope rechaza un alcance inválido', function (): void {
    expect(fn () => CompensationScope::fromString('GALAXY'))->toThrow(CompensationException::class);
});
