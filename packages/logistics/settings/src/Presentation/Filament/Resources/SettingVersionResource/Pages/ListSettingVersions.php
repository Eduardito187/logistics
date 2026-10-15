<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource;

/**
 * Listado de versiones de configuración: agrupado por clave, es el historial de versiones.
 */
final class ListSettingVersions extends ListRecords
{
    protected static string $resource = SettingVersionResource::class;

    /**
     * La única acción es publicar una nueva versión; editar y borrar no existen (filas inmutables).
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Publish new version')),
        ];
    }
}
