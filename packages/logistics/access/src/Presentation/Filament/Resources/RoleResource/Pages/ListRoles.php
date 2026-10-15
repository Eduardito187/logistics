<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Access\Presentation\Filament\Resources\RoleResource;

/**
 * Listado de roles en el admin.
 */
final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Define role')),
        ];
    }
}
