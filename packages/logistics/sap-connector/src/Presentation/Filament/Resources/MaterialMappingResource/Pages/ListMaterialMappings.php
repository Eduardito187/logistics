<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource;

/**
 * Listado de mapeos de material SAP con acción de alta.
 */
final class ListMaterialMappings extends ListRecords
{
    protected static string $resource = MaterialMappingResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
