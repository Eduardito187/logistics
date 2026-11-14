<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\ValueObject\VehicleClass;

/**
 * Enum de clase de vehículo por la que se tarifa un corredor: valores estables y etiqueta en inglés.
 */
it('expone los valores estables de cada clase', function (): void {
    expect(VehicleClass::Trailer->value)->toBe('trailer')
        ->and(VehicleClass::Truck->value)->toBe('truck')
        ->and(VehicleClass::ThirdParty->value)->toBe('third_party');
});

it('reconstruye la clase desde su valor', function (): void {
    expect(VehicleClass::from('trailer'))->toBe(VehicleClass::Trailer)
        ->and(VehicleClass::from('third_party'))->toBe(VehicleClass::ThirdParty);
});

it('rotula cada clase en inglés', function (): void {
    expect(VehicleClass::Trailer->label())->toBe('Trailer')
        ->and(VehicleClass::Truck->label())->toBe('Truck')
        ->and(VehicleClass::ThirdParty->label())->toBe('Third party');
});
