<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\DefineChecklistTemplate\DefineChecklistTemplate;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de una plantilla del checklist: reemplaza su contenido completo con DefineChecklistTemplate
 * (con id) por el CommandBus. Las inspecciones ya registradas no cambian: guardaron su propia evaluación.
 */
final class EditChecklistTemplate extends EditRecord
{
    protected static string $resource = ChecklistTemplateResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $name = $data['name'] ?? null;
        $vehicleType = $data['vehicle_type'] ?? null;
        $vehicleType = is_string($vehicleType) && $vehicleType !== '' ? $vehicleType : null;

        try {
            app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
                name: is_string($name) ? $name : '',
                items: ChecklistTemplateResource::normalizeItems($data['items'] ?? []),
                vehicleType: $vehicleType,
                active: (bool) ($data['active'] ?? true),
                templateId: (string) $record->getKey(),
            ));
        } catch (FleetException $e) {
            Notification::make()
                ->title(__('Could not save the checklist template'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return $record->refresh();
    }
}
