<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;

/**
 * Detalle de un bulto en el admin.
 */
final class ViewPhysicalUnit extends ViewRecord
{
    protected static string $resource = PhysicalUnitResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
