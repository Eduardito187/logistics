<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource;

/**
 * Listado de documentos de flota.
 */
final class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New document')),
        ];
    }
}
