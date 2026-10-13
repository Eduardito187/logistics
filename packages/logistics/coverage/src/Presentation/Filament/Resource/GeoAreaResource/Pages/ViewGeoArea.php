<?php

declare(strict_types=1);

namespace Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource;

/**
 * Ficha de un área geográfica administrativa (visor de solo lectura).
 *
 * Reutiliza el esquema de formulario del resource para mostrar nombre, nivel y área padre; permite
 * saltar a la edición desde la cabecera.
 */
final class ViewGeoArea extends ViewRecord
{
    protected static string $resource = GeoAreaResource::class;

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
