<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;

/**
 * Enum del ciclo de vida del viaje: vigencia (programado/en ruta), valores para filtrar SQL y etiqueta.
 */
it('expone los valores estables de cada estado', function (): void {
    expect(CarrierTripStatus::Scheduled->value)->toBe('scheduled')
        ->and(CarrierTripStatus::InProgress->value)->toBe('in_progress')
        ->and(CarrierTripStatus::Closed->value)->toBe('closed')
        ->and(CarrierTripStatus::Cancelled->value)->toBe('cancelled');
});

it('considera vigentes solo los estados programado y en ruta', function (): void {
    expect(CarrierTripStatus::Scheduled->isActive())->toBeTrue()
        ->and(CarrierTripStatus::InProgress->isActive())->toBeTrue()
        ->and(CarrierTripStatus::Closed->isActive())->toBeFalse()
        ->and(CarrierTripStatus::Cancelled->isActive())->toBeFalse();
});

it('lista los valores vigentes para filtrar en SQL', function (): void {
    expect(CarrierTripStatus::activeValues())->toBe(['scheduled', 'in_progress']);
});

it('rotula cada estado en inglés', function (): void {
    expect(CarrierTripStatus::Scheduled->label())->toBe('Scheduled')
        ->and(CarrierTripStatus::InProgress->label())->toBe('In progress')
        ->and(CarrierTripStatus::Closed->label())->toBe('Closed')
        ->and(CarrierTripStatus::Cancelled->label())->toBe('Cancelled');
});
