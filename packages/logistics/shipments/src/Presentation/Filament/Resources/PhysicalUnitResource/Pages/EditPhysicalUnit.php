<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;

/**
 * Edición de una unidad física en el admin.
 */
final class EditPhysicalUnit extends EditRecord
{
    protected static string $resource = PhysicalUnitResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
