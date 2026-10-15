<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Capacity\Presentation\Filament\Resources\CapacityBucketResource;

/**
 * Vista de detalle de un cupo de capacidad en el admin.
 *
 * No hay edición CRUD: desde aquí se operan los flujos transaccionales de consumir y liberar capacidad,
 * que despachan sus Command al CommandBus.
 */
final class ViewCapacityBucket extends ViewRecord
{
    protected static string $resource = CapacityBucketResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CapacityBucketResource::consumeAction(),
            CapacityBucketResource::releaseAction(),
        ];
    }
}
