<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Telemetry\Presentation\Filament\Resources\GeofenceResource;

/**
 * Listado de geocercas en el admin.
 */
final class ListGeofences extends ListRecords
{
    protected static string $resource = GeofenceResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Define geofence')),
        ];
    }
}
