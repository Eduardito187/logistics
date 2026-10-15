<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\VehicleProfileUpdated;
use Logistics\Fleet\Domain\Event\VehicleRegistered;
use Logistics\Fleet\Domain\Event\VehicleStatusChanged;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleProfile;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;

function registrarVehiculo(array $documents = []): Vehicle
{
    return Vehicle::register(
        VehicleId::generate(),
        PlateNumber::of('1234-ABC'),
        Capacity::of(3500, 18.0),
        'node-cd-scz',
        $documents,
    );
}

it('registra un vehículo disponible y graba el evento', function (): void {
    $vehicle = registrarVehiculo();

    expect($vehicle->status())->toBe(VehicleStatus::Available)
        ->and($vehicle->plate()->value)->toBe('1234-ABC')
        ->and($vehicle->homeBaseNodeId())->toBe('node-cd-scz');

    $events = $vehicle->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleRegistered::class)
        ->and($events[0]->eventName())->toBe('fleet.vehicle_registered');
});

it('normaliza la placa a mayúsculas', function (): void {
    expect(PlateNumber::of('abc-123')->value)->toBe('ABC-123');
});

it('va a mantenimiento y vuelve a servicio grabando el cambio', function (): void {
    $vehicle = registrarVehiculo();
    $vehicle->releaseEvents();

    $vehicle->sendToMaintenance();
    expect($vehicle->status())->toBe(VehicleStatus::Maintenance);

    $vehicle->returnToService();
    expect($vehicle->status())->toBe(VehicleStatus::Available);

    $events = $vehicle->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(VehicleStatusChanged::class)
        ->and($events[0]->from)->toBe('available')
        ->and($events[0]->to)->toBe('maintenance')
        ->and($events[1]->to)->toBe('available');
});

it('no graba evento si el estado no cambia', function (): void {
    $vehicle = registrarVehiculo();
    $vehicle->releaseEvents();

    $vehicle->changeStatus(VehicleStatus::Available);

    expect($vehicle->releaseEvents())->toBe([]);
});

it('lista los documentos por vencer dentro de la ventana', function (): void {
    $ref = new DateTimeImmutable('2026-10-13');
    $vehicle = registrarVehiculo([
        DocumentExpiry::of('soat', new DateTimeImmutable('2026-10-20')),      // dentro de 7 días
        DocumentExpiry::of('inspeccion', new DateTimeImmutable('2026-12-31')), // fuera de la ventana
        DocumentExpiry::of('extintor', new DateTimeImmutable('2026-10-01')),   // ya vencido
    ]);

    $expiring = $vehicle->documentsExpiringWithin(10, $ref);

    expect($expiring)->toHaveCount(2)
        ->and(array_map(fn ($d) => $d->type, $expiring))->toBe(['soat', 'extintor']);
});

it('permite agregar documentos después de registrar', function (): void {
    $vehicle = registrarVehiculo();
    $vehicle->addDocument(DocumentExpiry::of('soat', new DateTimeImmutable('2026-10-15')));

    expect($vehicle->documents())->toHaveCount(1)
        ->and($vehicle->documentsExpiringWithin(5, new DateTimeImmutable('2026-10-13')))->toHaveCount(1);
});

it('actualiza la ficha técnica y graba el evento con el payload primitivo', function (): void {
    $vehicle = registrarVehiculo();
    $vehicle->releaseEvents();

    $vehicle->updateProfile(new VehicleProfile(
        make: 'Volvo',
        model: 'FH-460',
        modelYear: 2021,
        vin: 'YV2RT40A8MB123456',
        engineNumber: 'ENG-778899',
        color: 'White',
        photoFront: 'fleet-vehicle-photos/front.jpg',
        photoBack: 'fleet-vehicle-photos/back.jpg',
        photoLeft: 'fleet-vehicle-photos/left.jpg',
        photoRight: 'fleet-vehicle-photos/right.jpg',
    ));

    expect($vehicle->make())->toBe('Volvo')
        ->and($vehicle->model())->toBe('FH-460')
        ->and($vehicle->modelYear())->toBe(2021)
        ->and($vehicle->vin())->toBe('YV2RT40A8MB123456')
        ->and($vehicle->engineNumber())->toBe('ENG-778899')
        ->and($vehicle->color())->toBe('White')
        ->and($vehicle->photoFront())->toBe('fleet-vehicle-photos/front.jpg')
        ->and($vehicle->photoRight())->toBe('fleet-vehicle-photos/right.jpg');

    $events = $vehicle->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VehicleProfileUpdated::class)
        ->and($events[0]->eventName())->toBe('fleet.vehicle.profile_updated')
        ->and($events[0]->payload())->toBe([
            'make' => 'Volvo',
            'model' => 'FH-460',
            'model_year' => 2021,
            'vin' => 'YV2RT40A8MB123456',
            'engine_number' => 'ENG-778899',
            'color' => 'White',
            'photo_front' => 'fleet-vehicle-photos/front.jpg',
            'photo_back' => 'fleet-vehicle-photos/back.jpg',
            'photo_left' => 'fleet-vehicle-photos/left.jpg',
            'photo_right' => 'fleet-vehicle-photos/right.jpg',
        ]);
});

it('registra un vehículo sin ficha técnica (todos los atributos nulos)', function (): void {
    $vehicle = registrarVehiculo();

    expect($vehicle->make())->toBeNull()
        ->and($vehicle->modelYear())->toBeNull()
        ->and($vehicle->photoFront())->toBeNull();
});
