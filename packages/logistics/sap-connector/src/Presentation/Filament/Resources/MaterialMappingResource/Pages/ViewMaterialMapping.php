<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource;

/**
 * Vista de detalle de un mapeo de material SAP (solo lectura), con accesos a edición y baja.
 */
final class ViewMaterialMapping extends ViewRecord
{
    protected static string $resource = MaterialMappingResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
