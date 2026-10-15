<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource;

/**
 * Vista de detalle de una tripulación en el admin (solo lectura).
 *
 * No se expone edición: una tripulación se arma para una fecha y no se modifica (se arma otra).
 */
final class ViewCrew extends ViewRecord
{
    protected static string $resource = CrewResource::class;
}
