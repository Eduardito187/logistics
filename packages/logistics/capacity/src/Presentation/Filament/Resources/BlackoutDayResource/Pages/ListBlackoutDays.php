<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Logistics\Capacity\Presentation\Filament\Resources\BlackoutDayResource;

/**
 * Listado de días bloqueados de despacho en el admin.
 *
 * El alta no es un CRUD: la cabecera expone el flujo transaccional "Block a day", que despacha
 * BlockDispatchDayCommand al CommandBus en vez de escribir el record directamente.
 */
final class ListBlackoutDays extends ListRecords
{
    protected static string $resource = BlackoutDayResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            BlackoutDayResource::blockAction(),
        ];
    }
}
