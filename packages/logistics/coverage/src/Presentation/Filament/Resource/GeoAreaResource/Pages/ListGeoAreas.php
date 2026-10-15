<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource;

/**
 * Listado de áreas geográficas administrativas.
 */
final class ListGeoAreas extends ListRecords
{
    protected static string $resource = GeoAreaResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
