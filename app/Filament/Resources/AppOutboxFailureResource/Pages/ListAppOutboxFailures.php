<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppOutboxFailureResource\Pages;

use App\Filament\Resources\AppOutboxFailureResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de acciones offline rechazadas que reportaron los teléfonos. Sin acciones de cabecera: las filas
 * las crea la app (ver {@see AppOutboxFailureResource}).
 */
final class ListAppOutboxFailures extends ListRecords
{
    protected static string $resource = AppOutboxFailureResource::class;
}
