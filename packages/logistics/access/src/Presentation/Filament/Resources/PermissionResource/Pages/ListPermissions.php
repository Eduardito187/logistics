<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource;

/**
 * Listado del catálogo de permisos en el admin.
 */
final class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register permission')),
        ];
    }
}
