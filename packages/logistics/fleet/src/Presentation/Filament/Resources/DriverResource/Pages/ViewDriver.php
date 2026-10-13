<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;

/**
 * Vista de detalle de un chofer en el admin.
 *
 * Desde la cabecera se puede editar el perfil (UpdateDriverProfile) y registrar documentos del chofer.
 */
final class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('driver'),
        ];
    }
}
