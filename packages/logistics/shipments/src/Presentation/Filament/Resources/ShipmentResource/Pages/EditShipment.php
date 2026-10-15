<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

/**
 * Edición de los campos administrables de un pedido recibido.
 */
final class EditShipment extends EditRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
