<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;

/**
 * Vista de detalle de un chofer en el admin (solo lectura).
 *
 * No se expone edición: el dominio aún no tiene un comando para modificar un chofer registrado.
 */
final class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;
}
