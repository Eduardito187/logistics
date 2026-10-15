<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuel;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\RefuelResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una carga de combustible desde el admin.
 *
 * No persiste con Eloquent: despacha RecordRefuel al bus CQRS (el dominio genera el id y calcula el
 * costo total). El precio por litro se captura en bolivianos y se convierte a centavos. Como el comando
 * no devuelve el id, se recupera el record recién creado (vehículo + instante, el más reciente).
 */
final class CreateRefuel extends CreateRecord
{
    protected static string $resource = RefuelResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];
        $refueledAt = Carbon::parse((string) $data['refueled_at'])->toIso8601String();
        $pricePerLiterCents = (int) round(((float) $data['price_per_liter_bs']) * 100);

        app(CommandBus::class)->dispatch(new RecordRefuel(
            vehicleId: $vehicleId,
            driverId: null,
            fuelType: (string) $data['fuel_type'],
            liters: (float) $data['liters'],
            pricePerLiterCents: $pricePerLiterCents,
            currency: (string) $data['currency'],
            odometerKm: isset($data['odometer_km']) && is_numeric($data['odometer_km']) ? (int) $data['odometer_km'] : null,
            station: isset($data['station']) && is_string($data['station']) && $data['station'] !== '' ? $data['station'] : null,
            fullTank: (bool) ($data['full_tank'] ?? false),
            refueledAt: $refueledAt,
            note: isset($data['note']) && is_string($data['note']) && $data['note'] !== '' ? $data['note'] : null,
        ));

        // UUID v7 es ordenable en el tiempo: el id más alto del vehículo es la carga recién creada.
        return RefuelRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
