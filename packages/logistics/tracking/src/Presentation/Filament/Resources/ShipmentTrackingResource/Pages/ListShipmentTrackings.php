<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

/**
 * Listado del read model de seguimiento.
 *
 * No hay CRUD de creación (la proyección no se edita a mano), pero sí el FLUJO transaccional de abrir
 * el seguimiento de un envío: la acción de cabecera despacha OpenTracking por el CommandBus.
 */
final class ListShipmentTrackings extends ListRecords
{
    protected static string $resource = ShipmentTrackingResource::class;

    /**
     * Acción de cabecera: abrir seguimiento (OpenTracking). No es un "New" de CRUD; dispara el caso de
     * uso real por el bus.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ShipmentTrackingResource::openTrackingAction(),
        ];
    }
}
