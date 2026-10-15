<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Fleet\Presentation\Filament\Support\OwnerDocuments;

/**
 * Vista de detalle de un ayudante en el admin.
 *
 * Desde la cabecera se puede editar el perfil (UpdateHelperProfile) y registrar documentos del ayudante.
 */
final class ViewHelper extends ViewRecord
{
    protected static string $resource = HelperResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            OwnerDocuments::registerAction('helper'),
        ];
    }
}
