<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource;

/**
 * Edición de un área geográfica.
 */
final class EditGeoArea extends EditRecord
{
    protected static string $resource = GeoAreaResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
