<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource;

/**
 * Listado de tripulaciones armadas.
 */
final class ListCrews extends ListRecords
{
    protected static string $resource = CrewResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Assemble crew')),
        ];
    }
}
