<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;

/**
 * Listado de entregas en ejecución.
 *
 * El encabezado expone el primer flujo del ciclo operativo —el escaneo de preparación— que abre la
 * entrega del pedido. El resto de los escaneos (carga, descarga, recepción en nodo, prueba de entrega
 * y fallo) viven como row actions sobre cada entrega ya abierta. Todos despachan su Command por el
 * CommandBus; nunca se escribe el Record directo.
 */
final class ListDeliveries extends ListRecords
{
    protected static string $resource = DeliveryResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeliveryResource::preparationScanAction(),
        ];
    }
}
