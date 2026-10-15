<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource;

/**
 * Listado de cupos de capacidad en el admin.
 *
 * El alta no es un CRUD: la cabecera expone el flujo transaccional "Define capacity", que despacha
 * DefineCapacityCommand al CommandBus en vez de escribir el record directamente.
 */
final class ListCapacityBuckets extends ListRecords
{
    protected static string $resource = CapacityBucketResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CapacityBucketResource::defineAction(),
        ];
    }
}
