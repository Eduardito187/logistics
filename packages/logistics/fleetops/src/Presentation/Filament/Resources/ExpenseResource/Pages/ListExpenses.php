<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource;

/**
 * Listado de gastos de flota en el admin.
 */
final class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Record expense')),
        ];
    }
}
