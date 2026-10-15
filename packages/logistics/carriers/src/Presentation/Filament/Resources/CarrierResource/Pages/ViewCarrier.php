<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierResource;

/**
 * Vista de detalle de un transportista en el admin.
 */
final class ViewCarrier extends ViewRecord
{
    protected static string $resource = CarrierResource::class;
}
