<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages;

use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Application\Command\DefineVehicleProfile\DefineVehicleProfile;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición del perfil técnico de un vehículo desde el admin.
 *
 * No escribe el record con Eloquent: re-despacha DefineVehicleProfile al bus CQRS (es un upsert por
 * `vehicle_id`). El vehículo es fijo (es la PK), así que se toma del record, no del formulario.
 */
final class EditVehicleProfile extends EditRecord
{
    protected static string $resource = VehicleProfileResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $vehicleId = (string) $record->getKey();

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
