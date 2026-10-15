<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource;

/**
 * Vista de detalle de una inspección pre-salida en el admin (solo lectura).
 *
 * No se expone edición: una inspección es un registro inmutable de lo verificado ese día.
 */
final class ViewPreTripChecklist extends ViewRecord
{
    protected static string $resource = PreTripChecklistResource::class;
}
