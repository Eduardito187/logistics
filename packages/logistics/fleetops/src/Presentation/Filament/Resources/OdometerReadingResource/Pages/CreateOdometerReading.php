<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\RecordOdometer\RecordOdometer;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\OdometerReadingRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\OdometerReadingResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una lectura de odómetro desde el admin.
 *
 * No persiste con Eloquent: despacha RecordOdometer al bus CQRS (el dominio genera el id). Como el comando
 * no devuelve el id, se recupera el record recién creado del vehículo (UUID v7 ordenable: el id más alto es
 * el nuevo) para la redirección.
 */
final class CreateOdometerReading extends CreateRecord
{
    protected static string $resource = OdometerReadingResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];

        app(CommandBus::class)->dispatch(new RecordOdometer(
            vehicleId: $vehicleId,
            km: (int) $data['km'],
            source: (string) $data['source'],
            readAt: Carbon::parse((string) $data['read_at'])->toIso8601String(),
            note: isset($data['note']) && is_string($data['note']) && $data['note'] !== '' ? $data['note'] : null,
        ));

        return OdometerReadingRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
