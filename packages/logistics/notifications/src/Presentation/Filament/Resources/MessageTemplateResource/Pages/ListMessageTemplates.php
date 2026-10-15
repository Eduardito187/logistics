<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Notifications\Presentation\Filament\Resources\MessageTemplateResource;

/**
 * Listado de plantillas de mensaje, con acción de creación en el encabezado.
 */
final class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
