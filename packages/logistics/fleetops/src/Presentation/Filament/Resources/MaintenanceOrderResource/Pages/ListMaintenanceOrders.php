<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource;

/**
 * Listado de órdenes de mantenimiento en el admin.
 */
final class ListMaintenanceOrders extends ListRecords
{
    protected static string $resource = MaintenanceOrderResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Schedule maintenance')),
        ];
    }
}
