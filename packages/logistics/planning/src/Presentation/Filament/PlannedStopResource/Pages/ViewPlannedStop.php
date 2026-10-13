<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\PlannedStopResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Planning\Presentation\Filament\PlannedStopResource;

/**
 * Vista de una parada: envío, plan, posición en la secuencia, ETA y carril de carga.
 */
final class ViewPlannedStop extends ViewRecord
{
    protected static string $resource = PlannedStopResource::class;
}
