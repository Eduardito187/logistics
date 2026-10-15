<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\PlanResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Planning\Presentation\Filament\PlanResource;

/**
 * Vista de un plan: cabecera (día, camión, estado) y su secuencia de paradas con ETA y carril.
 */
final class ViewPlan extends ViewRecord
{
    protected static string $resource = PlanResource::class;
}
