<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\PrinterResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Labels\Presentation\Filament\Resources\PrinterResource;

/**
 * Listado de impresoras de red registradas.
 */
final class ListPrinters extends ListRecords
{
    protected static string $resource = PrinterResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Register printer')),
        ];
    }
}
