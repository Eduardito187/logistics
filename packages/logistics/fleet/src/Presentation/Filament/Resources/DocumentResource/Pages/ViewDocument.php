<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DocumentResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\DocumentResource;

/**
 * Vista de detalle de un documento de flota en el admin (solo lectura).
 *
 * No se expone edición: un documento se registra y, si cambia, se registra uno nuevo.
 */
final class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;
}
