<?php

declare(strict_types=1);

use Logistics\Settlement\Domain\ValueObject\SettlementStatus;

it('expone el valor crudo de cada estado del ciclo de vida', function (): void {
    expect(SettlementStatus::Draft->value)->toBe('draft')
        ->and(SettlementStatus::Confirmed->value)->toBe('confirmed')
        ->and(SettlementStatus::Paid->value)->toBe('paid');
});

it('reconstruye el estado desde su valor crudo con from()', function (): void {
    expect(SettlementStatus::from('draft'))->toBe(SettlementStatus::Draft)
        ->and(SettlementStatus::from('confirmed'))->toBe(SettlementStatus::Confirmed)
        ->and(SettlementStatus::from('paid'))->toBe(SettlementStatus::Paid);
});

it('traduce cada estado a su etiqueta en inglés para el admin', function (): void {
    expect(SettlementStatus::Draft->label())->toBe('Draft')
        ->and(SettlementStatus::Confirmed->label())->toBe('Confirmed')
        ->and(SettlementStatus::Paid->label())->toBe('Paid');
});

it('enumera exactamente los tres estados válidos', function (): void {
    $values = array_map(static fn (SettlementStatus $status): string => $status->value, SettlementStatus::cases());

    expect($values)->toBe(['draft', 'confirmed', 'paid']);
});
