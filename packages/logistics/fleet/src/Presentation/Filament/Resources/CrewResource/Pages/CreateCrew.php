<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\CrewResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Presentation\Filament\Resources\CrewResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Armado de una tripulación desde el admin.
 *
 * Despacha AssembleCrew por el CommandBus (el UUID lo genera el dominio) y relee la tripulación recién
 * creada por chofer + vehículo + fecha, tomando la más reciente, para que Filament muestre el resultado.
 */
final class CreateCrew extends CreateRecord
{
    protected static string $resource = CrewResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var list<string> $helperIds */
        $helperIds = array_values($data['helper_ids'] ?? []);

        $shiftStartsAt = isset($data['shift_starts_at']) && $data['shift_starts_at'] !== ''
            ? (string) $data['shift_starts_at']
            : null;
        $shiftEndsAt = isset($data['shift_ends_at']) && $data['shift_ends_at'] !== ''
            ? (string) $data['shift_ends_at']
            : null;

        app(CommandBus::class)->dispatch(new AssembleCrew(
            driverId: (string) $data['driver_id'],
            helperIds: $helperIds,
            vehicleId: (string) $data['vehicle_id'],
            assembledFor: (string) $data['assembled_for'],
            shiftStartsAt: $shiftStartsAt,
            shiftEndsAt: $shiftEndsAt,
        ));

        return CrewModel::query()
            ->where('driver_id', $data['driver_id'])
            ->where('vehicle_id', $data['vehicle_id'])
            ->where('assembled_for', $data['assembled_for'])
            ->latest()
            ->firstOrFail();
    }
}
