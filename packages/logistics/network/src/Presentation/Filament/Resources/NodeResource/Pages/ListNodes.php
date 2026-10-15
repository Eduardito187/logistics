<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;

/**
 * Listado de nodos en el admin. El alta es un flujo de negocio: despacha RegisterNodeCommand (ver
 * CreateNode), no un CRUD plano.
 */
final class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Register node'))
                ->icon('heroicon-o-plus'),
        ];
    }
}
