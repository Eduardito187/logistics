<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource;

/**
 * Listado de cargas de combustible en el admin.
 */
final class ListRefuels extends ListRecords
{
    protected static string $resource = RefuelResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Record refuel')),
        ];
    }
}
