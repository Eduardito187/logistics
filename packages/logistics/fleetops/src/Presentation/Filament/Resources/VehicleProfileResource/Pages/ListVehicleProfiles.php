<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\VehicleProfileResource;

/**
 * Listado de perfiles técnicos de vehículos en el admin.
 */
final class ListVehicleProfiles extends ListRecords
{
    protected static string $resource = VehicleProfileResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Define vehicle profile')),
        ];
    }
}
