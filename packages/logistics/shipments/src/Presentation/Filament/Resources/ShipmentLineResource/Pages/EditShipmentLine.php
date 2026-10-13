<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;

/**
 * Edición de una línea de pedido en el admin.
 */
final class EditShipmentLine extends EditRecord
{
    protected static string $resource = ShipmentLineResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
