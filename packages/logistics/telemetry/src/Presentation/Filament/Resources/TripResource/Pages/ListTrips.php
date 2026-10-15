<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\TripResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Telemetry\Presentation\Filament\Resources\TripResource;

/**
 * Listado de trips en el admin (solo lectura).
 */
final class ListTrips extends ListRecords
{
    protected static string $resource = TripResource::class;
}
