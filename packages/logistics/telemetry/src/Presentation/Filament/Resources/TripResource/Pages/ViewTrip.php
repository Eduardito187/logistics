<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource;

/**
 * Vista de detalle de un trip en el admin (solo lectura).
 */
final class ViewTrip extends ViewRecord
{
    protected static string $resource = TripResource::class;
}
