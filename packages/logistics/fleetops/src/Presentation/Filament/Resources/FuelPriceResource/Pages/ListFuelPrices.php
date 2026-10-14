<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource;

/**
 * Listado de precios de combustible en el admin.
 */
final class ListFuelPrices extends ListRecords
{
    protected static string $resource = FuelPriceResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Set fuel price')),
        ];
    }
}
