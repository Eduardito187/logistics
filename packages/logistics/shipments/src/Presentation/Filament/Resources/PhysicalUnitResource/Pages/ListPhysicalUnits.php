<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;

/**
 * Listado de unidades físicas en el admin.
 */
final class ListPhysicalUnits extends ListRecords
{
    protected static string $resource = PhysicalUnitResource::class;

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
