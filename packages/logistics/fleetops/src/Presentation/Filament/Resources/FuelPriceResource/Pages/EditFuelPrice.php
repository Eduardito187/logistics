<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Application\Command\UpdateFuelPrice\UpdateFuelPrice;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un precio de combustible desde el admin.
 *
 * No escribe el record con Eloquent: despacha el comando UpdateFuelPrice al bus CQRS. El precio se edita
 * en bolivianos y se convierte a centavos aquí; el id es fijo (la PK) y se toma del record.
 */
final class EditFuelPrice extends EditRecord
{
    protected static string $resource = FuelPriceResource::class;

    /**
     * Rellena el campo de precio en bolivianos a partir de los centavos persistidos.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $cents = isset($data['price_cents']) ? (int) $data['price_cents'] : 0;
        $data['price_bs'] = number_format($cents / 100, 2, '.', '');

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $id = (string) $record->getKey();
        $effectiveFrom = (string) $data['effective_from'];
        $priceCents = (int) round(((float) $data['price_bs']) * 100);

        app(CommandBus::class)->dispatch(new UpdateFuelPrice(
            id: $id,
            priceCents: $priceCents,
            currency: (string) $data['currency'],
            effectiveFrom: substr($effectiveFrom, 0, 10),
            source: isset($data['source']) && is_string($data['source']) && $data['source'] !== '' ? $data['source'] : null,
            fuelType: isset($data['fuel_type']) && is_string($data['fuel_type']) && $data['fuel_type'] !== '' ? $data['fuel_type'] : null,
        ));

        return FuelPriceRecord::query()->findOrFail($id);
    }
}
