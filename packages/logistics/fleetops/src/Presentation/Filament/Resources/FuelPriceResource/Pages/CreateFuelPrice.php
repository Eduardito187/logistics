<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Application\Command\SetFuelPrice\SetFuelPrice;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\FuelPriceResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un precio de combustible desde el admin.
 *
 * No persiste con Eloquent: despacha el comando SetFuelPrice al bus CQRS (el dominio genera el id). El
 * precio se captura en bolivianos y se convierte a centavos aquí. Como el comando no devuelve el id, se
 * recupera el record recién creado (tipo + fecha de vigencia, el más reciente) para la redirección.
 */
final class CreateFuelPrice extends CreateRecord
{
    protected static string $resource = FuelPriceResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $fuelType = (string) $data['fuel_type'];
        $effectiveFrom = (string) $data['effective_from'];
        $priceCents = (int) round(((float) $data['price_bs']) * 100);

        app(CommandBus::class)->dispatch(new SetFuelPrice(
            fuelType: $fuelType,
            priceCents: $priceCents,
            currency: (string) $data['currency'],
            effectiveFrom: substr($effectiveFrom, 0, 10),
            source: isset($data['source']) && is_string($data['source']) && $data['source'] !== '' ? $data['source'] : null,
        ));

        // UUID v7 es ordenable en el tiempo: el id más alto del tipo/fecha es el recién creado.
        return FuelPriceRecord::query()
            ->where('fuel_type', $fuelType)
            ->where('effective_from', substr($effectiveFrom, 0, 10))
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
