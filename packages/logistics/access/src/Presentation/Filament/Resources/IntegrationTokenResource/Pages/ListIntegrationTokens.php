<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource;

/**
 * Listado de tokens de integración en el admin.
 */
final class ListIntegrationTokens extends ListRecords
{
    protected static string $resource = IntegrationTokenResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Issue token')),
        ];
    }
}
