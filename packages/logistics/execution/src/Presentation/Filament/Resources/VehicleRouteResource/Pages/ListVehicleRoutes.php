<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Execution\Presentation\Filament\Resources\VehicleRouteResource;

/**
 * Listado de rutas del chofer por camión (solo lectura).
 */
final class ListVehicleRoutes extends ListRecords
{
    protected static string $resource = VehicleRouteResource::class;
}
