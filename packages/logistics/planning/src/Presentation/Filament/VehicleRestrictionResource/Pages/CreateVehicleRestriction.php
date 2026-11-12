<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\VehicleRestrictionRecord;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de las restricciones de un camión. No persiste con Eloquent: despacha SetVehicleRestrictions (upsert
 * por camión: si ya tenía restricciones, se reemplazan) y relee el record para la redirección.
 */
final class CreateVehicleRestriction extends CreateRecord
{
    protected static string $resource = VehicleRestrictionResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = is_string($data['vehicle_id'] ?? null) ? $data['vehicle_id'] : '';

        try {
            app(CommandBus::class)->dispatch(VehicleRestrictionResource::commandFrom($vehicleId, $data));
        } catch (PlanningException $exception) {
            Notification::make()
                ->title(__('Could not save the restrictions'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return VehicleRestrictionRecord::query()->findOrFail($vehicleId);
    }
}
