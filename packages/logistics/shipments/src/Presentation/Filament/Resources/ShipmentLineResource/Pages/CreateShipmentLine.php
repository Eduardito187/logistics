<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentLineResource;

/**
 * Alta de una línea de pedido en el admin.
 */
final class CreateShipmentLine extends CreateRecord
{
    protected static string $resource = ShipmentLineResource::class;
}
