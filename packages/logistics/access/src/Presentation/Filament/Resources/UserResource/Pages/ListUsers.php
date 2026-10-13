<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\UserResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Access\Presentation\Filament\Resources\UserResource;

/**
 * Listado de usuarios en el admin.
 */
final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register user')),
        ];
    }
}
