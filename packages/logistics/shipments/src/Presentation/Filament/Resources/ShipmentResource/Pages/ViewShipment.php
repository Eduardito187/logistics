<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

/**
 * Detalle de un pedido recibido en el admin.
 */
final class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

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
