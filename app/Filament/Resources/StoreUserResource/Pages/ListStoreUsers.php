<?php

declare(strict_types=1);

namespace App\Filament\Resources\StoreUserResource\Pages;

use App\Filament\Resources\StoreUserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de cuentas de usuario para asignarles su nodo de tienda.
 *
 * No hay alta ni baja de usuarios desde aquí: la pantalla solo gestiona la pertenencia a una tienda
 * (ver {@see StoreUserResource}), por eso no expone acciones de cabecera.
 */
final class ListStoreUsers extends ListRecords
{
    protected static string $resource = StoreUserResource::class;
}
