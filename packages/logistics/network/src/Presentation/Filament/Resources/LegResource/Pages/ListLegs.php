<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\LegResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Network\Presentation\Filament\Resources\LegResource;

/**
 * Listado de tramos/corredores en el admin. El alta es un flujo de negocio: despacha DefineLegCommand
 * (ver CreateLeg), no un CRUD plano.
 */
final class ListLegs extends ListRecords
{
    protected static string $resource = LegResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('Define leg'))
                ->icon('ds-plus'),
        ];
    }
}
