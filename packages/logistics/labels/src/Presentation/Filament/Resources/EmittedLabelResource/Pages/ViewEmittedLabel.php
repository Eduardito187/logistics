<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;

/**
 * Detalle de una etiqueta emitida: incluye el contenido firmado del QR y su ZPL (solo lectura).
 */
final class ViewEmittedLabel extends ViewRecord
{
    protected static string $resource = EmittedLabelResource::class;
}
