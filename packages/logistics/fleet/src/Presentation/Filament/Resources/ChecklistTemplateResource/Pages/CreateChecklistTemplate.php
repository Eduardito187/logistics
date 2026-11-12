<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\DefineChecklistTemplate\DefineChecklistTemplate;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\ChecklistTemplateModel;
use Logistics\Fleet\Presentation\Filament\Resources\ChecklistTemplateResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una plantilla del checklist desde el admin: despacha DefineChecklistTemplate por el CommandBus
 * (el UUID lo genera el dominio) y relee la plantilla recién creada (la más reciente con ese nombre).
 * Un error de negocio (clave repetida, sin ítems) se muestra como notificación y no se guarda nada.
 */
final class CreateChecklistTemplate extends CreateRecord
{
    protected static string $resource = ChecklistTemplateResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = $data['name'] ?? null;
        $name = is_string($name) ? $name : '';
        $vehicleType = $data['vehicle_type'] ?? null;
        $vehicleType = is_string($vehicleType) && $vehicleType !== '' ? $vehicleType : null;

        try {
            app(CommandBus::class)->dispatch(new DefineChecklistTemplate(
                name: $name,
                items: ChecklistTemplateResource::normalizeItems($data['items'] ?? []),
                vehicleType: $vehicleType,
                active: (bool) ($data['active'] ?? true),
            ));
        } catch (FleetException $e) {
            Notification::make()
                ->title(__('Could not save the checklist template'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return ChecklistTemplateModel::query()
            ->where('name', trim($name))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
