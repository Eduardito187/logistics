<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;

/**
 * Listado de ayudantes de la flota.
 */
final class ListHelpers extends ListRecords
{
    protected static string $resource = HelperResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New helper')),
        ];
    }
}
