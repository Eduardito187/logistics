<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\Carriers\Application\Command\SetTariff\SetTariff;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\TariffRecord;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de una tarifa desde el admin.
 *
 * No persiste con Eloquent: despacha el comando SetTariff al bus CQRS (el dominio genera el id de la
 * tarifa). Como el comando no devuelve el id, se recupera la tarifa recién creada por su combinación
 * (transportista + corredor + clase de vehículo, la más reciente) para la redirección de Filament.
 */
final class CreateTariff extends CreateRecord
{
    protected static string $resource = TariffResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        app(CommandBus::class)->dispatch(new SetTariff(
            carrierId: (string) $data['carrier_id'],
            corridorFrom: (string) $data['corridor_from'],
            corridorTo: (string) $data['corridor_to'],
            corridorType: (string) $data['corridor_type'],
            vehicleClass: (string) $data['vehicle_class'],
            priceCents: (int) $data['price_cents'],
            currency: (string) $data['currency'],
            effectiveFrom: (string) $data['effective_from'],
        ));

        return TariffRecord::query()
            ->where('carrier_id', (string) $data['carrier_id'])
            ->where('corridor_from', (string) $data['corridor_from'])
            ->where('corridor_to', (string) $data['corridor_to'])
            ->where('corridor_type', (string) $data['corridor_type'])
            ->where('vehicle_class', (string) $data['vehicle_class'])
            ->latest('id')
            ->firstOrFail();
    }
}
