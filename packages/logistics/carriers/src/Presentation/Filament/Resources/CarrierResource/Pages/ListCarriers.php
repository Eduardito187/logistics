<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;

/**
 * Listado de transportistas en el admin.
 */
final class ListCarriers extends ListRecords
{
    protected static string $resource = CarrierResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register carrier')),
        ];
    }
}
