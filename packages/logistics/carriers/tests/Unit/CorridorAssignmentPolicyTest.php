<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Service\CorridorAssignmentPolicy;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;

it('elige la clase de vehículo según el volumen', function (float $volume, VehicleClass $expected): void {
    expect((new CorridorAssignmentPolicy)->classForVolume($volume))->toBe($expected);
})->with([
    'tráiler en el umbral' => [40.0, VehicleClass::Trailer],
    'tráiler por encima' => [65.5, VehicleClass::Trailer],
    'camión en el umbral' => [12.0, VehicleClass::Truck],
    'camión intermedio' => [30.0, VehicleClass::Truck],
    'tercero por debajo' => [5.0, VehicleClass::ThirdParty],
]);

it('rechaza un volumen no positivo', function (float $volume): void {
    expect(fn () => (new CorridorAssignmentPolicy)->classForVolume($volume))
        ->toThrow(CarriersException::class, 'mayor que cero');
})->with([0.0, -3.0]);
