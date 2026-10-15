<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource;

/**
 * Listado de salidas/despachos de la flota en el admin.
 */
final class ListDepartures extends ListRecords
{
    protected static string $resource = DepartureResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register departure')),
        ];
    }
}
