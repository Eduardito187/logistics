<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource;

/**
 * Listado de sellers en el admin.
 */
final class ListSellers extends ListRecords
{
    protected static string $resource = SellerResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register seller')),
        ];
    }
}
