<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;

/**
 * Detalle de una línea de pedido en el admin.
 */
final class ViewShipmentLine extends ViewRecord
{
    protected static string $resource = ShipmentLineResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
