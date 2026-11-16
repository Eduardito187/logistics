<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Fleet\Presentation\Filament\Resources\PreTripChecklistResource;

/**
 * Listado de inspecciones pre-salida (SOLO LECTURA): el checklist se realiza desde el camión (app del
 * chofer), no desde el admin. Sin acción de alta; cada registro se consulta con la vista de detalle.
 */
final class ListPreTripChecklists extends ListRecords
{
    protected static string $resource = PreTripChecklistResource::class;
}
