<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource;

/**
 * Listado de inspecciones pre-salida.
 */
final class ListPreTripChecklists extends ListRecords
{
    protected static string $resource = PreTripChecklistResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New pre-trip checklist')),
        ];
    }
}
