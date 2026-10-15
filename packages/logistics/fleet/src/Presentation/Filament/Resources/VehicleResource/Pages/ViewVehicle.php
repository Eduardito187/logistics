<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;

/**
 * Vista de detalle de un vehículo en el admin.
 *
 * Desde la cabecera se puede editar la ficha técnica (UpdateVehicleProfile) y registrar documentos del
 * vehículo (SOAT, inspección técnica, etc.).
 */
final class ViewVehicle extends ViewRecord
{
    protected static string $resource = VehicleResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('vehicle'),
        ];
    }
}
