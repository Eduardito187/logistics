<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource;

/**
 * Listado de lecturas de odómetro en el admin.
 */
final class ListOdometerReadings extends ListRecords
{
    protected static string $resource = OdometerReadingResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Record odometer')),
        ];
    }
}
