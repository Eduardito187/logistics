<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\HelperResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\SetHelperAvailability\SetHelperAvailability;
use Logistics\Fleet\Presentation\Filament\Resources\HelperResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un ayudante desde el admin.
 *
 * El único cambio posible es la disponibilidad (activo / baja): despacha SetHelperAvailability por el
 * CommandBus. Los demás campos del formulario están deshabilitados (no hay comando que los modifique).
 */
final class EditHelper extends EditRecord
{
    protected static string $resource = HelperResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(CommandBus::class)->dispatch(new SetHelperAvailability(
            helperId: (string) $record->getKey(),
            active: (bool) ($data['active'] ?? true),
        ));

        return $record->refresh();
    }
}
