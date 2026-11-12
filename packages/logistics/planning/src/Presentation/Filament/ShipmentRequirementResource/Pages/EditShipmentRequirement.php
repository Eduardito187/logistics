<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de los requisitos de un envío: despacha SetShipmentRequirement con el mismo envío (la PK no
 * cambia). Desde la cabecera se pueden quitar por completo (RemoveShipmentRequirement).
 */
final class EditShipmentRequirement extends EditRecord
{
    protected static string $resource = ShipmentRequirementResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Tras quitarlos el record deja de existir: se vuelve al listado.
            ShipmentRequirementResource::removeAction()
                ->after(function (): void {
                    $this->redirect(ShipmentRequirementResource::getUrl('index'));
                }),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            app(CommandBus::class)->dispatch(
                ShipmentRequirementResource::commandFrom((string) $record->getKey(), $data),
            );
        } catch (PlanningException $exception) {
            Notification::make()
                ->title(__('Could not save the requirement'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return $record->refresh();
    }
}
