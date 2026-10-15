<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Filament\Resources\LegResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Network\Presentation\Filament\Resources\LegResource;

/**
 * Vista de detalle de un tramo/corredor en el admin.
 *
 * La única escritura disponible es el flujo de eliminación (RemoveLegCommand), expuesto como acción de
 * cabecera que despacha el comando por el bus; un tramo no se edita.
 */
final class ViewLeg extends ViewRecord
{
    protected static string $resource = LegResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            LegResource::removeAction(),
        ];
    }
}
