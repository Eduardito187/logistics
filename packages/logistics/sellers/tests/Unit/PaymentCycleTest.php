<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\ValueObject\PaymentCycle;

/**
 * Cobertura del enum PaymentCycle del contexto sellers: valores estables y etiqueta en inglés para el
 * admin (regla 5 de CLAUDE.md). PHP puro, sin Laravel.
 */
it('expone los valores estables de cada ciclo de pago', function (): void {
    expect(PaymentCycle::Weekly->value)->toBe('weekly')
        ->and(PaymentCycle::Biweekly->value)->toBe('biweekly')
        ->and(PaymentCycle::Monthly->value)->toBe('monthly')
        ->and(PaymentCycle::cases())->toHaveCount(3);
});

it('se reconstituye desde su valor primitivo', function (): void {
    expect(PaymentCycle::from('weekly'))->toBe(PaymentCycle::Weekly)
        ->and(PaymentCycle::from('biweekly'))->toBe(PaymentCycle::Biweekly)
        ->and(PaymentCycle::from('monthly'))->toBe(PaymentCycle::Monthly);
});

it('da una etiqueta en inglés para cada ciclo', function (PaymentCycle $cycle, string $label): void {
    expect($cycle->label())->toBe($label);
})->with([
    'semanal' => [PaymentCycle::Weekly, 'Weekly'],
    'quincenal' => [PaymentCycle::Biweekly, 'Biweekly'],
    'mensual' => [PaymentCycle::Monthly, 'Monthly'],
]);
