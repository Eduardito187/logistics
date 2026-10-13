<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Settings\Presentation\Filament\Resources\SettingVersionResource;

/**
 * Detalle de una versión de configuración (solo lectura): clave, número, valor JSON y auditoría.
 */
final class ViewSettingVersion extends ViewRecord
{
    protected static string $resource = SettingVersionResource::class;

    /**
     * Sin acciones de cabecera: las versiones son inmutables, no se editan ni se borran.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
