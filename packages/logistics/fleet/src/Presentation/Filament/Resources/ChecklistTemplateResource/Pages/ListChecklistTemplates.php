<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource;

/**
 * Listado de plantillas del checklist pre-salida.
 */
final class ListChecklistTemplates extends ListRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New checklist template')),
        ];
    }
}
