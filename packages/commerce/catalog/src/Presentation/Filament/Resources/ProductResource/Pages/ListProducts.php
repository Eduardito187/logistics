<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages;

use Commerce\Catalog\Presentation\Filament\Resources\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de productos del catálogo en el admin.
 */
final class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Draft product')),
        ];
    }
}
