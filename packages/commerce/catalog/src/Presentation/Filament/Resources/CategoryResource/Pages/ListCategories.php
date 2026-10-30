<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\CategoryResource\Pages;

use Commerce\Catalog\Presentation\Filament\Resources\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de categorías del catálogo en el admin.
 */
final class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Create category')),
        ];
    }
}
