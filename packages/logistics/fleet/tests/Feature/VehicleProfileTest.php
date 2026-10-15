<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\RegisterDocument\RegisterDocument;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Fleet\Application\Command\UpdateVehicleProfile\UpdateVehicleProfile;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Tests de integración de la ficha técnica del vehículo y sus documentos contra PostgreSQL
 * (base logistics_test). Cubren el round-trip de UpdateVehicleProfile por el CommandBus y el registro de
 * un documento del vehículo con referencia (RegisterDocument).
 */
beforeEach(function (): void {
    //
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Registra un vehículo por el bus y devuelve su id (UUID generado por el dominio).
 */
function registrarVehiculoFeature(): string
{
    $plate = strtoupper('FT'.random_int(1000, 9999));

    app(CommandBus::class)->dispatch(new RegisterVehicle(
        plate: $plate,
        capacityKg: 3500,
        capacityM3: 18.0,
        homeBaseNodeId: Uuid::generate()->value,
        documents: [],
    ));

    return (string) VehicleModel::query()->where('plate', $plate)->firstOrFail()->getKey();
}

it('actualiza la ficha técnica del vehículo y la persiste', function (): void {
    $vehicleId = registrarVehiculoFeature();

    app(CommandBus::class)->dispatch(new UpdateVehicleProfile(
        vehicleId: $vehicleId,
        make: 'Hino',
        model: 'FC9J',
        modelYear: 2022,
        vin: '9F1AB12C345678901',
        engineNumber: 'ENG-554433',
        color: 'Blue',
        photoFront: 'fleet-vehicle-photos/front.jpg',
    ));

    $row = VehicleModel::query()->findOrFail($vehicleId);

    expect($row->make)->toBe('Hino')
        ->and($row->model)->toBe('FC9J')
        ->and($row->model_year)->toBe(2022)
        ->and($row->vin)->toBe('9F1AB12C345678901')
        ->and($row->engine_number)->toBe('ENG-554433')
        ->and($row->color)->toBe('Blue')
        ->and($row->photo_front)->toBe('fleet-vehicle-photos/front.jpg');
});

it('falla al actualizar la ficha de un vehículo inexistente', function (): void {
    app(CommandBus::class)->dispatch(new UpdateVehicleProfile(
        vehicleId: Uuid::generate()->value,
        make: 'Isuzu',
    ));
})->throws(FleetException::class);

it('registra un documento del vehículo con referencia (SOAT) y lo persiste', function (): void {
    $vehicleId = registrarVehiculoFeature();

    app(CommandBus::class)->dispatch(new RegisterDocument(
        ownerType: 'vehicle',
        ownerId: $vehicleId,
        documentType: 'soat',
        expiresOn: '2027-01-01',
        issuedOn: '2026-01-01',
        reference: 'POL-2026-00987',
    ));

    $document = FleetDocumentModel::query()
        ->where('owner_type', 'vehicle')
        ->where('owner_id', $vehicleId)
        ->firstOrFail();

    expect($document->document_type)->toBe('soat')
        ->and($document->reference)->toBe('POL-2026-00987')
        ->and($document->expires_on)->toBe('2027-01-01');
});
