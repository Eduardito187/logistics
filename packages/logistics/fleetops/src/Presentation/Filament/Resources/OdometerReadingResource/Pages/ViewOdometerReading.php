<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource;

/**
 * Vista de detalle de una lectura de odómetro en el admin.
 */
final class ViewOdometerReading extends ViewRecord
{
    protected static string $resource = OdometerReadingResource::class;
}
