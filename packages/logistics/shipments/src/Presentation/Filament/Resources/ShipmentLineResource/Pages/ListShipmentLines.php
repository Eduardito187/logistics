<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;

/**
 * Listado de líneas de pedido en el admin.
 */
final class ListShipmentLines extends ListRecords
{
    protected static string $resource = ShipmentLineResource::class;

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
