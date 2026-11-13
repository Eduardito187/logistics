<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\ValueObject\SettlementStatus;

/**
 * Enum del ciclo de vida de la liquidación: valores estables y etiqueta en inglés para la consola.
 */
it('expone los valores estables de cada estado', function (): void {
    expect(SettlementStatus::Submitted->value)->toBe('submitted')
        ->and(SettlementStatus::Approved->value)->toBe('approved')
        ->and(SettlementStatus::Paid->value)->toBe('paid')
        ->and(SettlementStatus::Disputed->value)->toBe('disputed');
});

it('reconstruye el estado desde su valor', function (): void {
    expect(SettlementStatus::from('submitted'))->toBe(SettlementStatus::Submitted)
        ->and(SettlementStatus::from('disputed'))->toBe(SettlementStatus::Disputed);
});

it('rotula cada estado en inglés', function (): void {
    expect(SettlementStatus::Submitted->label())->toBe('Submitted')
        ->and(SettlementStatus::Approved->label())->toBe('Approved')
        ->and(SettlementStatus::Paid->label())->toBe('Paid')
        ->and(SettlementStatus::Disputed->label())->toBe('Disputed');
});
