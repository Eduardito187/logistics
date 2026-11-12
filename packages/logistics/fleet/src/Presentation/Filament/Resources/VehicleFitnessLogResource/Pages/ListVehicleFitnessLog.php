<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleFitnessLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleFitnessLogResource;

/**
 * Listado (solo lectura) de la bitácora de aptitud de los vehículos.
 */
final class ListVehicleFitnessLog extends ListRecords
{
    protected static string $resource = VehicleFitnessLogResource::class;
}
