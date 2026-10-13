<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\ChangeVehicleStatus\ChangeVehicleStatus;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un vehículo desde el admin.
 *
 * El único cambio posible es el estado operativo: despacha ChangeVehicleStatus por el CommandBus.
 * Los demás campos del formulario están deshabilitados (no hay comando que los modifique).
 */
final class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(CommandBus::class)->dispatch(new ChangeVehicleStatus(
            vehicleId: (string) $record->getKey(),
            status: (string) $data['status'],
        ));

        return $record->refresh();
    }
}
