<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un chofer desde el admin.
 *
 * Despacha RegisterDriver por el CommandBus (el UUID lo genera el dominio) y relee el registro por su
 * número de licencia (único) para que Filament muestre el resultado.
 */
final class CreateDriver extends CreateRecord
{
    protected static string $resource = DriverResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $enabledVehicleIds */
        $enabledVehicleIds = array_values($data['enabled_vehicle_ids'] ?? []);

        app(CommandBus::class)->dispatch(new RegisterDriver(
            name: (string) $data['name'],
            licenseNumber: (string) $data['license_number'],
            licenseExpiry: (string) $data['license_expiry'],
            enabledVehicleIds: $enabledVehicleIds,
            active: (bool) ($data['active'] ?? true),
            licenseDocumentType: (string) ($data['license_document_type'] ?? 'driver_license'),
        ));

        return DriverModel::query()->where('license_number', $data['license_number'])->firstOrFail();
    }
}
