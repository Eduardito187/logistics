<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de las restricciones de un camión: despacha SetVehicleRestrictions con el mismo camión (la PK no
 * cambia). Desde la cabecera se pueden quitar por completo (RemoveVehicleRestrictions).
 */
final class EditVehicleRestriction extends EditRecord
{
    protected static string $resource = VehicleRestrictionResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // Tras quitarlas el record deja de existir: se vuelve al listado.
            VehicleRestrictionResource::removeAction()
                ->after(function (): void {
                    $this->redirect(VehicleRestrictionResource::getUrl('index'));
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
                VehicleRestrictionResource::commandFrom((string) $record->getKey(), $data),
            );
        } catch (PlanningException $exception) {
            Notification::make()
                ->title(__('Could not save the restrictions'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return $record->refresh();
    }
}
