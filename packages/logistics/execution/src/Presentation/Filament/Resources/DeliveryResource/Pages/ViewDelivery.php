<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;

/**
 * Vista de detalle de una entrega con sus escaneos (solo lectura).
 */
final class ViewDelivery extends ViewRecord
{
    protected static string $resource = DeliveryResource::class;
}
