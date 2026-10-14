<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\ScheduleMaintenance\ScheduleMaintenance;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta (agendado) de una orden de mantenimiento desde el admin.
 *
 * No persiste con Eloquent: despacha ScheduleMaintenance al bus CQRS (el dominio genera el id). Como el
 * comando no devuelve el id, se recupera la orden recién creada (vehículo, la más reciente por UUID v7).
 */
final class CreateMaintenanceOrder extends CreateRecord
{
    protected static string $resource = MaintenanceOrderResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];

        app(CommandBus::class)->dispatch(new ScheduleMaintenance(
            vehicleId: $vehicleId,
            type: (string) $data['type'],
            odometerKm: isset($data['odometer_km']) && is_numeric($data['odometer_km']) ? (int) $data['odometer_km'] : null,
            workshop: isset($data['workshop']) && is_string($data['workshop']) && $data['workshop'] !== '' ? $data['workshop'] : null,
            scheduledAt: Carbon::parse((string) $data['scheduled_at'])->toIso8601String(),
            note: isset($data['note']) && is_string($data['note']) && $data['note'] !== '' ? $data['note'] : null,
        ));

        // UUID v7 es ordenable en el tiempo: el id más alto del vehículo es la orden recién creada.
        return MaintenanceOrderRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
