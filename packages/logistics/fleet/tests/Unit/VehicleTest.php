<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\VehicleRegistered;
use Logistics\Fleet\Domain\Event\VehicleStatusChanged;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
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
