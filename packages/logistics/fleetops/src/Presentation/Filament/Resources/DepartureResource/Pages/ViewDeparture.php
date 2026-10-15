<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\DepartureResource;

/**
 * Vista de detalle de una salida/despacho en el admin.
 */
final class ViewDeparture extends ViewRecord
{
    protected static string $resource = DepartureResource::class;
}
