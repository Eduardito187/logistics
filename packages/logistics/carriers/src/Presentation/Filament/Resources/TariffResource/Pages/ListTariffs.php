<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource;

/**
 * Listado de tarifas por corredor en el admin.
 */
final class ListTariffs extends ListRecords
{
    protected static string $resource = TariffResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Set tariff')),
        ];
    }
}
