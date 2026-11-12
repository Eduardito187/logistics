<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource;

/**
 * Listado de viajes de transportista (filtrable por transportista y estado) con alta.
 */
final class ListCarrierTrips extends ListRecords
{
    protected static string $resource = CarrierTripResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Schedule trip')),
        ];
    }
}
