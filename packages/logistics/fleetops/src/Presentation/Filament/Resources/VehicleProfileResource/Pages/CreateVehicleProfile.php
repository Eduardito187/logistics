<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Application\Command\DefineVehicleProfile\DefineVehicleProfile;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta del perfil técnico de un vehículo desde el admin.
 *
 * No persiste con Eloquent: despacha DefineVehicleProfile al bus CQRS (upsert por `vehicle_id`). Como la
 * PK del perfil ES el `vehicle_id`, el record recién creado se recupera directamente por esa clave.
 */
final class CreateVehicleProfile extends CreateRecord
{
    protected static string $resource = VehicleProfileResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];

        app(CommandBus::class)->dispatch(new DefineVehicleProfile(
            vehicleId: $vehicleId,
            brand: isset($data['brand']) && is_string($data['brand']) && $data['brand'] !== '' ? $data['brand'] : null,
            model: isset($data['model']) && is_string($data['model']) && $data['model'] !== '' ? $data['model'] : null,
            year: isset($data['year']) && $data['year'] !== '' ? (int) $data['year'] : null,
            fuelType: isset($data['fuel_type']) && is_string($data['fuel_type']) && $data['fuel_type'] !== '' ? $data['fuel_type'] : null,
            tankLiters: isset($data['tank_liters']) && $data['tank_liters'] !== '' ? (float) $data['tank_liters'] : null,
            vin: isset($data['vin']) && is_string($data['vin']) && $data['vin'] !== '' ? $data['vin'] : null,
            color: isset($data['color']) && is_string($data['color']) && $data['color'] !== '' ? $data['color'] : null,
        ));

        return VehicleProfileRecord::query()->findOrFail($vehicleId);
    }
}
