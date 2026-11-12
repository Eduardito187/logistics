<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Infrastructure\Persistence\Eloquent\ShipmentRequirementRecord;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de los requisitos de ruteo de un envío. No persiste con Eloquent: despacha SetShipmentRequirement
 * (upsert por envío: si ya tenía requisitos, se reemplazan) y relee el record para la redirección.
 */
final class CreateShipmentRequirement extends CreateRecord
{
    protected static string $resource = ShipmentRequirementResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $shipmentId = is_string($data['shipment_id'] ?? null) ? $data['shipment_id'] : '';

        try {
            app(CommandBus::class)->dispatch(ShipmentRequirementResource::commandFrom($shipmentId, $data));
        } catch (PlanningException $exception) {
            Notification::make()
                ->title(__('Could not save the requirement'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            throw new Halt;
        }

        return ShipmentRequirementRecord::query()->findOrFail($shipmentId);
    }
}
