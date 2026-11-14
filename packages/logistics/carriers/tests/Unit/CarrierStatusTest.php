<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\ValueObject\CarrierStatus;

/**
 * Enum de estados del transportista: valores estables (contrato con la base) y etiqueta en inglés.
 */
it('expone los valores estables de cada estado', function (): void {
    expect(CarrierStatus::Active->value)->toBe('active')
        ->and(CarrierStatus::Suspended->value)->toBe('suspended')
        ->and(CarrierStatus::from('active'))->toBe(CarrierStatus::Active)
        ->and(CarrierStatus::from('suspended'))->toBe(CarrierStatus::Suspended);
});

it('rotula cada estado en inglés para el admin', function (): void {
    expect(CarrierStatus::Active->label())->toBe('Active')
        ->and(CarrierStatus::Suspended->label())->toBe('Suspended');
});
