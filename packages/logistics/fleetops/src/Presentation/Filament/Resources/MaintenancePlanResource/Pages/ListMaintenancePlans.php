<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource;

/**
 * Listado de planes de mantenimiento en el admin.
 */
final class ListMaintenancePlans extends ListRecords
{
    protected static string $resource = MaintenancePlanResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Define maintenance plan')),
        ];
    }
}
