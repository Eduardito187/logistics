<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\NodeResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Network\Presentation\Filament\Resources\NodeResource;

/**
 * Vista de detalle de un nodo de la red en el admin.
 *
 * La única escritura disponible es el flujo de baja (DeactivateNodeCommand), expuesto como acción de
 * cabecera que despacha el comando por el bus; no hay edición directa del nodo.
 */
final class ViewNode extends ViewRecord
{
    protected static string $resource = NodeResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            NodeResource::deactivateAction(),
        ];
    }
}
