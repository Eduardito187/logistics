<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\PlannedStopResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Planning\Presentation\Filament\PlannedStopResource;

/**
 * Listado de paradas del plan. Solo lectura: sin acción de crear (las paradas nacen por comando).
 */
final class ListPlannedStops extends ListRecords
{
    protected static string $resource = PlannedStopResource::class;
}
