<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource;

/**
 * Vista de detalle del perfil técnico de un vehículo en el admin.
 */
final class ViewVehicleProfile extends ViewRecord
{
    protected static string $resource = VehicleProfileResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('Edit')),
        ];
    }
}
