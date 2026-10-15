<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource;

/**
 * Ficha de una zona de cobertura (visor básico): identidad, área padre y reglas vigentes.
 */
final class ViewZone extends ViewRecord
{
    protected static string $resource = ZoneResource::class;

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
