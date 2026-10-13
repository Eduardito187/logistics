<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

/**
 * Detalle de un seguimiento: estado visible, ETA, paradas restantes y última posición.
 *
 * No hay "Edit" de CRUD (la proyección no se edita a mano), pero sí los FLUJOS operativos sobre el
 * registro: avanzar el estado visible (ProjectExecutionEvent) y reportar la posición del camión
 * (UpdateTruckPosition), ambos despachados por el CommandBus.
 */
final class ViewShipmentTracking extends ViewRecord
{
    protected static string $resource = ShipmentTrackingResource::class;

    /**
     * Acciones de cabecera: los mismos flujos transaccionales que ofrece la fila de la tabla, para
     * operarlos desde el detalle. No hay edición directa del read model.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ShipmentTrackingResource::projectExecutionEventAction(),
            ShipmentTrackingResource::updateTruckPositionAction(),
        ];
    }
}
